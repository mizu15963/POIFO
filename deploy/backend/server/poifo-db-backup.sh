#!/usr/bin/env bash
# DB を mysqldump して暗号化し、バックアップ用 S3 バケットに置く（cron から1日1回。setup-server.sh が設定する）。
# マネージドDB の自動バックアップ（7日分）とは別に、AWS の外でも戻せる形で残すためのもの。
# DB の接続情報はアプリの .env から読む。S3 には置くことしかできないユーザー（poifo-backup-writer）で送る。
#
# 戻し方（手元PCなどで）:
#   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:backup.pass -in poifo-....sql.gz.enc | gunzip > dump.sql
set -euo pipefail

CONF=${POIFO_BACKUP_CONFIG:-/etc/poifo/backup.env}
ENV_FILE=${POIFO_APP_ENV:-/var/www/poifo/shared/.env}
CA=/etc/poifo/rds-global-bundle.pem

# shellcheck disable=SC1090
. "$CONF"
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION
[[ -n "${BACKUP_BUCKET:-}" ]] || { echo "BACKUP_BUCKET が空です" >&2; exit 1; }
[[ -s "${BACKUP_PASSPHRASE_FILE:-}" ]] || { echo "パスフレーズのファイルがありません: ${BACKUP_PASSPHRASE_FILE:-}" >&2; exit 1; }

# .env から1項目読む（前後の引用符を外す）。source しない（.env はシェルの書式とは限らないため）
env_get() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }
DB_HOST=$(env_get DB_HOST); DB_PORT=$(env_get DB_PORT); DB_DATABASE=$(env_get DB_DATABASE)
DB_USERNAME=$(env_get DB_USERNAME); DB_PASSWORD=$(env_get DB_PASSWORD)

# パスワードをコマンドライン（ps で見える）に出さないため、一時ファイルで渡す
cnf=$(mktemp); trap 'rm -f "$cnf"' EXIT; chmod 600 "$cnf"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\nssl-ca=%s\nssl-mode=VERIFY_CA\n' \
  "$DB_HOST" "${DB_PORT:-3306}" "$DB_USERNAME" "$DB_PASSWORD" "$CA" > "$cnf"

key="db/${DB_DATABASE}-$(date +%Y%m%d-%H%M%S).sql.gz.enc"
echo "$(date '+%F %T') 開始: s3://${BACKUP_BUCKET}/${key}"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --routines --triggers \
  --no-tablespaces --set-gtid-purged=OFF "$DB_DATABASE" \
  | gzip -9 \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:${BACKUP_PASSPHRASE_FILE}" \
  | aws s3 cp - "s3://${BACKUP_BUCKET}/${key}" --only-show-errors
echo "$(date '+%F %T') 完了"
