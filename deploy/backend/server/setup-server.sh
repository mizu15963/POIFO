#!/usr/bin/env bash
# POIFO の API サーバ（Lightsail、Ubuntu 24.04）の初期設定。root で実行する。
# 何度実行してもよい（入っているものは飛ばし、設定ファイルは置き直す）。設定を変えたら直して再実行する。
# 1台目・増やした台・入れ替えで作った新しい台、どれも同じ手順で使う。
#
#   git clone https://github.com/mizu15963/POIFO.git /opt/poifo-src
#   sudo /opt/poifo-src/deploy/backend/server/setup-server.sh
#
# 初回は /etc/poifo/server.env・deploy-agent.env・/var/www/poifo/shared/.env の雛形を作って止まるので、
# 値を入れてからもう一度実行する（手順は docs/server/README.md）。
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
CONF_DIR=/etc/poifo
APP_DIR=/var/www/poifo
PHP_VER=8.4

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'エラー: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "root で実行してください（sudo $0）"
. /etc/os-release
[[ "${ID}" == "ubuntu" && "${VERSION_ID}" == "24.04" ]] || die "Ubuntu 24.04 向けです（今は ${PRETTY_NAME}）"

# ---------------- 設定ファイル（なければ雛形を置いて止まる） ----------------
install -d -m 700 "$CONF_DIR"
missing=()
[[ -f $CONF_DIR/server.env ]] || { install -m 600 "$HERE/server.env.example" "$CONF_DIR/server.env"; missing+=("$CONF_DIR/server.env"); }
[[ -f $CONF_DIR/deploy-agent.env ]] || { install -m 600 "$HERE/deploy-agent.env.example" "$CONF_DIR/deploy-agent.env"; missing+=("$CONF_DIR/deploy-agent.env"); }

# shellcheck disable=SC1091
. "$CONF_DIR/server.env"
ORIGIN_HOST=${ORIGIN_HOST:-}
ISSUE_CERT=${ISSUE_CERT:-false}
CERTBOT_EMAIL=${CERTBOT_EMAIL:-}
SWAP_SIZE=${SWAP_SIZE:-2G}
ORIGIN_VERIFY_SECRET=${ORIGIN_VERIFY_SECRET:-}
if [[ ${#missing[@]} -eq 0 ]]; then
  [[ -n "$ORIGIN_VERIFY_SECRET" ]] || missing+=("$CONF_DIR/server.env（ORIGIN_VERIFY_SECRET）")
  if [[ -n "$ORIGIN_VERIFY_SECRET" && ! "$ORIGIN_VERIFY_SECRET" =~ ^[A-Za-z0-9_-]{32,}$ ]]; then
    die "ORIGIN_VERIFY_SECRET は32文字以上の英数字（GitHub Secrets と同じ値）にしてください"
  fi
  grep -q '^DEPLOY_BUCKET=.\+' "$CONF_DIR/deploy-agent.env" || missing+=("$CONF_DIR/deploy-agent.env（アクセスキー・DEPLOY_BUCKET・SERVER_NAME・MIGRATE）")
fi

# ---------------- OS ----------------
log "タイムゾーン・スワップ・自動更新"
timedatectl set-timezone Asia/Tokyo
if ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
  [[ -f /swapfile ]] || { fallocate -l "$SWAP_SIZE" /swapfile; chmod 600 /swapfile; mkswap /swapfile >/dev/null; }
  swapon /swapfile
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' > /etc/sysctl.d/99-poifo.conf && sysctl -q -p /etc/sysctl.d/99-poifo.conf

log "パッケージ"
export DEBIAN_FRONTEND=noninteractive
if ! grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
  apt-get update -q
  apt-get install -y -q software-properties-common
  add-apt-repository -y ppa:ondrej/php   # Ubuntu 24.04 の標準は PHP 8.3 のため
fi
apt-get update -q
apt-get install -y -q \
  nginx certbot jq unzip curl mysql-client unattended-upgrades \
  "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mbstring" "php${PHP_VER}-intl" "php${PHP_VER}-xml" \
  "php${PHP_VER}-curl" "php${PHP_VER}-mysql" "php${PHP_VER}-bcmath" "php${PHP_VER}-zip" "php${PHP_VER}-opcache"
dpkg-reconfigure -f noninteractive unattended-upgrades

if ! command -v aws >/dev/null; then
  log "AWS CLI"
  tmp=$(mktemp -d)
  curl -sSfL "https://awscli.amazonaws.com/awscli-exe-linux-$(uname -m).zip" -o "$tmp/awscliv2.zip"
  unzip -q "$tmp/awscliv2.zip" -d "$tmp" && "$tmp/aws/install"
  rm -rf "$tmp"
fi

# マネージドDB へ TLS で接続するための CA（.env の MYSQL_ATTR_SSL_CA に指定する）
curl -sSfL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem -o "$CONF_DIR/rds-global-bundle.pem.new" \
  && mv "$CONF_DIR/rds-global-bundle.pem.new" "$CONF_DIR/rds-global-bundle.pem"
chmod 755 "$CONF_DIR" && chmod 644 "$CONF_DIR/rds-global-bundle.pem"
chmod 600 "$CONF_DIR"/*.env

# ---------------- アプリのディレクトリ ----------------
log "ディレクトリと .env"
install -d -m 755 "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared" /var/www/letsencrypt
for d in app/public framework/cache/data framework/sessions framework/views logs; do
  install -d -o www-data -g www-data -m 775 "$APP_DIR/shared/storage/$d"
done
if [[ ! -f $APP_DIR/shared/.env ]]; then
  install -o root -g www-data -m 640 /dev/null "$APP_DIR/shared/.env"
  missing+=("$APP_DIR/shared/.env（項目は backend/README.md の「本番の設定」。2台目以降は1台目と同じ内容）")
fi
chown root:www-data "$APP_DIR/shared/.env" && chmod 640 "$APP_DIR/shared/.env"

if [[ ${#missing[@]} -gt 0 ]]; then
  printf '\n次のファイルに値を入れてから、もう一度実行してください:\n'
  printf '  - %s\n' "${missing[@]}"
  exit 2
fi

# ---------------- PHP ----------------
log "PHP ${PHP_VER}"
install -m 644 "$HERE/php-fpm-poifo.conf" "/etc/php/${PHP_VER}/fpm/pool.d/zz-poifo.conf"
install -m 644 "$HERE/php-poifo.ini" "/etc/php/${PHP_VER}/fpm/conf.d/99-poifo.ini"
install -m 644 "$HERE/php-poifo.ini" "/etc/php/${PHP_VER}/cli/conf.d/99-poifo.ini"
systemctl enable -q "php${PHP_VER}-fpm"
systemctl restart "php${PHP_VER}-fpm"

# ---------------- nginx と証明書 ----------------
render_nginx() { # $1: HTTPS を含めるか
  local https=""
  if [[ "$1" == "true" ]]; then
    https=$(sed "s|__ORIGIN_HOST__|${ORIGIN_HOST}|g" "$HERE/nginx/https-server.conf")
  fi
  # 秘密の値は英数字だけ（上で確認済み）なので、そのまま置き換えてよい
  sed -e "s|__ORIGIN_VERIFY_SECRET__|${ORIGIN_VERIFY_SECRET}|" "$HERE/nginx/poifo.conf.template" \
    | awk -v block="$https" '{ if ($0 == "__HTTPS_SERVER__") print block; else print }' \
    > /etc/nginx/sites-available/poifo.new
  chmod 640 /etc/nginx/sites-available/poifo.new && chown root:www-data /etc/nginx/sites-available/poifo.new
  mv /etc/nginx/sites-available/poifo.new /etc/nginx/sites-available/poifo
}

log "nginx"
install -m 644 "$HERE/nginx/poifo-app.conf" /etc/nginx/snippets/poifo-app.conf
rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/poifo /etc/nginx/sites-enabled/poifo
cert_dir="/etc/letsencrypt/live/${ORIGIN_HOST}"
have_cert=false
[[ -n "$ORIGIN_HOST" && -f "$cert_dir/fullchain.pem" ]] && have_cert=true
render_nginx "$have_cert"
nginx -t -q
systemctl enable -q nginx
systemctl reload nginx || systemctl restart nginx

if [[ "$ISSUE_CERT" == "true" && -n "$ORIGIN_HOST" && "$have_cert" == "false" ]]; then
  log "Let's Encrypt（${ORIGIN_HOST}）"
  email_args=(--register-unsafely-without-email)
  [[ -n "$CERTBOT_EMAIL" ]] && email_args=(--email "$CERTBOT_EMAIL")
  certbot certonly --webroot -w /var/www/letsencrypt -d "$ORIGIN_HOST" \
    --non-interactive --agree-tos "${email_args[@]}" \
    || die "証明書を取れませんでした。${ORIGIN_HOST} がこのサーバの固定 IP を指しているか（LB なしの段階か）確認してください"
  render_nginx true
  nginx -t -q && systemctl reload nginx
fi
# 更新後に nginx を読み直す（certbot の timer が自動で更新する）
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

# ---------------- deploy agent・キュー・cron ----------------
log "deploy agent・キュー・cron"
install -m 755 "$HERE/poifo-deploy-agent.sh" /usr/local/bin/poifo-deploy-agent
install -m 755 "$HERE/poifo-db-backup.sh" /usr/local/bin/poifo-db-backup
install -m 644 "$HERE/poifo-deploy-agent.service" "$HERE/poifo-deploy-agent.timer" "$HERE/poifo-queue.service" /etc/systemd/system/
install -m 644 "$HERE/poifo.cron" /etc/cron.d/poifo
systemctl daemon-reload
systemctl enable -q --now poifo-deploy-agent.timer
if [[ -e $APP_DIR/current ]]; then
  systemctl enable -q --now poifo-queue
else
  echo "まだデプロイされていないので、キューのワーカーは最初のデプロイの後に起動します（もう一度このスクリプトを実行する）"
fi

# DB のバックアップ（backup.env がある台だけ。通常は MIGRATE=true の1台）
if [[ -f $CONF_DIR/backup.env ]]; then
  chmod 600 "$CONF_DIR/backup.env"
  echo "30 3 * * * root /usr/local/bin/poifo-db-backup >> /var/log/poifo-db-backup.log 2>&1" > /etc/cron.d/poifo-db-backup
  chmod 644 /etc/cron.d/poifo-db-backup
else
  rm -f /etc/cron.d/poifo-db-backup
fi

log "完了"
echo "nginx: HTTP(80)$( [[ $have_cert == true || ( $ISSUE_CERT == true && -n $ORIGIN_HOST ) ]] && echo " / HTTPS(443, ${ORIGIN_HOST})")"
echo "確認: curl -s -o /dev/null -w '%{http_code}\n' http://localhost/up   # デプロイ後は 200"
