#!/usr/bin/env bash
# POIFO バックエンドの deploy agent（サーバ側）。systemd タイマーで1分ごとに root で動く。
#
#   1. S3 の releases/latest.json を読み、まだ取り込んでいないコミットなら
#   2. releases/<コミット>.tar.gz を取得してハッシュを確かめ、/var/www/poifo/releases/<日時>-<コミット> に展開
#   3. .env と storage を共有ディレクトリにつなぎ、migrate・キャッシュ作成
#   4. current のリンクを切り替え、PHP-FPM を reload、キューのワーカーを再起動
#   5. 結果を S3 の status/<デプロイID>/<サーバ名>.json に書く（GitHub Actions が台数分を待つ）
#   デプロイID = GitHub Actions の実行ID-試行回数。同じコミットでも Actions から再実行すれば取り込み直す
#
# API サーバが複数台のとき: migrate は MIGRATE=true の1台（primary）だけが行う。
#   primary は migrate の結果を status/<デプロイID>/_migrated.json に書き、
#   ほかの台（MIGRATE=false）はそれが success になるまで待ってから切り替える（新しいコードが古いテーブルで動かないように）
#
# 設定: /etc/poifo/deploy-agent.env（deploy-agent.env.example を参照）
set -Eeuo pipefail

CONFIG=${POIFO_DEPLOY_AGENT_CONFIG:-/etc/poifo/deploy-agent.env}
# shellcheck disable=SC1090
source "$CONFIG"
: "${DEPLOY_BUCKET:?}" "${AWS_DEFAULT_REGION:?}"
APP_DIR=${APP_DIR:-/var/www/poifo}
APP_USER=${APP_USER:-www-data}
PHP_FPM_SERVICE=${PHP_FPM_SERVICE:-php8.4-fpm}
KEEP_RELEASES=${KEEP_RELEASES:-5}
SERVER_NAME=${SERVER_NAME:-$(hostname -s)}
MIGRATE=${MIGRATE:-true}
MIGRATE_WAIT_SECONDS=${MIGRATE_WAIT_SECONDS:-600}
[[ "$SERVER_NAME" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "SERVER_NAME が不正: $SERVER_NAME" >&2; exit 1; }
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION

# 同時に2つ動かない
exec 9> /run/poifo-deploy-agent.lock
flock -n 9 || exit 0

log() { echo "[deploy-agent] $*"; }
artisan() { sudo -u "$APP_USER" php "$1/artisan" "${@:2}" --no-interaction; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# ---- 新しいリリースがあるか ----
if ! aws s3 cp --only-show-errors "s3://${DEPLOY_BUCKET}/releases/latest.json" "$work/latest.json" 2> "$work/err"; then
  grep -q '404\|Not Found\|does not exist' "$work/err" && exit 0 # まだ一度もデプロイしていない
  cat "$work/err" >&2
  exit 1
fi
sha=$(jq -r .sha "$work/latest.json")
sha256=$(jq -r .sha256 "$work/latest.json")
deploy_id=$(jq -r .deploy_id "$work/latest.json")
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { log "latest.json の sha が不正: $sha"; exit 1; }
[[ "$deploy_id" =~ ^[0-9]+-[0-9]+$ ]] || { log "latest.json の deploy_id が不正: $deploy_id"; exit 1; }

# 一度試したデプロイは（成功でも失敗でも）繰り返さない。やり直すときは Actions から再実行する（デプロイIDが変わる）
last_attempt=$(cat "$APP_DIR/.last_deploy_id" 2> /dev/null || true)
[[ "$deploy_id" == "$last_attempt" ]] && exit 0
echo "$deploy_id" > "$APP_DIR/.last_deploy_id"

release="$APP_DIR/releases/$(date -u +%Y%m%d%H%M%S)-${sha:0:7}"
previous=$(readlink -f "$APP_DIR/current" 2> /dev/null || true)
switched=false
step="準備"

report() { # report <success|failed> <message>
  jq -n --arg result "$1" --arg message "$2" --arg sha "$sha" --arg deploy_id "$deploy_id" \
    --arg server "$SERVER_NAME" --arg release "$(basename "$release")" --arg at "$(date -u +%FT%TZ)" \
    '{result: $result, message: $message, sha: $sha, deploy_id: $deploy_id, server: $server, release: $release, finished_at: $at}' \
    > "$work/status.json"
  # 結果を書けなくても（S3 に届かない等）ここで止めない。Actions 側は待ち時間切れで失敗になる
  aws s3 cp --only-show-errors "$work/status.json" "s3://${DEPLOY_BUCKET}/status/${deploy_id}/${SERVER_NAME}.json" \
    || log "結果を S3 に書けませんでした"
  log "$1: $2"
}

# primary が migrate の結果をほかの台に知らせる
migrated() { # migrated <success|failed>
  jq -n --arg result "$1" --arg server "$SERVER_NAME" --arg at "$(date -u +%FT%TZ)" \
    '{result: $result, server: $server, at: $at}' > "$work/migrated.json"
  aws s3 cp --only-show-errors "$work/migrated.json" "s3://${DEPLOY_BUCKET}/status/${deploy_id}/_migrated.json" \
    || log "migrate の結果を S3 に書けませんでした"
}

fail() {
  trap - ERR # fail の中の失敗で fail が繰り返し呼ばれないように
  set +e
  [[ "$MIGRATE" == true && "$step" == "migrate" ]] && migrated failed
  # 切り替え後に失敗したら、前のリリースに戻す
  if [[ "$switched" == true && -n "$previous" ]]; then
    ln -sfn "$previous" "$APP_DIR/current.tmp" && mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"
    systemctl reload "$PHP_FPM_SERVICE" || true
    report failed "「${step}」で失敗したため、前のリリース（$(basename "$previous")）に戻しました"
  else
    report failed "「${step}」で失敗しました（現在のリリースはそのまま）。サーバで journalctl -u poifo-deploy-agent を確認"
  fi
  rm -rf "$release"
  exit 1
}
trap fail ERR

log "取り込み開始: ${sha}（デプロイID ${deploy_id}）"

# ---- 取得・検証・展開 ----
step="取得・検証・展開"
aws s3 cp --only-show-errors "s3://${DEPLOY_BUCKET}/releases/${sha}.tar.gz" "$work/release.tar.gz"
echo "${sha256}  $work/release.tar.gz" | sha256sum -c --quiet -
mkdir -p "$release"
tar -xzf "$work/release.tar.gz" -C "$release"

# ---- 共有ファイルにつなぐ（.env と storage はリリースをまたいで残す） ----
step="共有ファイルの接続"
ln -sfn "$APP_DIR/shared/.env" "$release/.env"
rm -rf "$release/storage"
ln -sfn "$APP_DIR/shared/storage" "$release/storage"
mkdir -p "$release/bootstrap/cache"
chown -R "$APP_USER:$APP_USER" "$release/bootstrap/cache"

# ---- DB 更新・キャッシュ作成（切り替え前に行い、失敗したら切り替えない） ----
if [[ "$MIGRATE" == true ]]; then
  step="migrate"
  # --isolated: 誤って2台が MIGRATE=true でも、共有キャッシュ（DB）のロックで1台だけが実行する
  artisan "$release" migrate --force --isolated
  migrated success
fi
step="キャッシュ作成"
artisan "$release" config:cache
artisan "$release" route:cache
artisan "$release" view:cache
artisan "$release" event:cache

# ---- primary の migrate を待つ（MIGRATE=false の台だけ） ----
if [[ "$MIGRATE" != true ]]; then
  step="primary の migrate 待ち"
  waited=0
  until aws s3 cp --only-show-errors "s3://${DEPLOY_BUCKET}/status/${deploy_id}/_migrated.json" "$work/migrated.json" 2> /dev/null; do
    if ((waited >= MIGRATE_WAIT_SECONDS)); then
      log "primary の migrate が ${MIGRATE_WAIT_SECONDS} 秒たっても終わりません"
      false
    fi
    sleep 10
    waited=$((waited + 10))
  done
  [[ "$(jq -r .result "$work/migrated.json")" == success ]] || { log "primary の migrate が失敗しています"; false; }
fi

# ---- 切り替え ----
step="切り替え"
ln -sfn "$release" "$APP_DIR/current.tmp"
mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"
switched=true
systemctl reload "$PHP_FPM_SERVICE"
artisan "$APP_DIR/current" queue:restart # ワーカーは終了し、systemd が新しいコードで起動し直す

# ---- 古いリリースを消す ----
find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d | sort | head -n "-${KEEP_RELEASES}" | while read -r old; do
  [[ "$(readlink -f "$APP_DIR/current")" == "$old" ]] || rm -rf "$old"
done

trap - ERR
report success "$(basename "$release") に切り替えました"
