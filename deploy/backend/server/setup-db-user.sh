#!/usr/bin/env bash
# マネージドDB に、アプリ用のユーザーを作る（1回だけ。サーバ上で root で実行）。何度実行してもよい。
# マスターユーザー（dbadmin）はアプリに使わない。アプリ用ユーザーはデータベース poifo の中だけ操作できる
# （migrate でテーブルを作り変えるので、その DB の中では全権限）。
#
#   sudo ./setup-db-user.sh <DBのエンドポイント>         # 例: terraform/base の output db_endpoint
#   → dbadmin のパスワード（GitHub Secrets の DB_MASTER_PASSWORD）を聞かれる
#   → 作ったユーザーのパスワードを表示するので、.env の DB_USERNAME / DB_PASSWORD に入れる
#   パスワードを作り直すときは RESET_PASSWORD=true を付けて実行する
set -euo pipefail

HOST=${1:?使い方: $0 <DBのエンドポイント>}
DB_NAME=${DB_NAME:-poifo}
APP_USER=${APP_USER:-poifo_app}
MASTER_USER=${MASTER_USER:-dbadmin}
CA=/etc/poifo/rds-global-bundle.pem
[[ -f $CA ]] || { echo "$CA がありません。先に setup-server.sh を実行してください" >&2; exit 1; }

read -r -s -p "${MASTER_USER} のパスワード: " master_pw; echo
cnf=$(mktemp); trap 'rm -f "$cnf"' EXIT; chmod 600 "$cnf"
printf '[client]\nhost=%s\nuser=%s\npassword="%s"\nssl-ca=%s\nssl-mode=VERIFY_CA\n' "$HOST" "$MASTER_USER" "$master_pw" "$CA" > "$cnf"
sql() { mysql --defaults-extra-file="$cnf" -N -B -e "$1"; }

exists=$(sql "SELECT COUNT(*) FROM mysql.user WHERE user='${APP_USER}' AND host='%'")
pw=""
if [[ "$exists" == "0" || "${RESET_PASSWORD:-false}" == "true" ]]; then
  pw=$(openssl rand -hex 20)
  if [[ "$exists" == "0" ]]; then
    sql "CREATE USER '${APP_USER}'@'%' IDENTIFIED BY '${pw}' REQUIRE SSL"
  else
    sql "ALTER USER '${APP_USER}'@'%' IDENTIFIED BY '${pw}'"
  fi
fi
sql "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
sql "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${APP_USER}'@'%'"

echo "ユーザー ${APP_USER} の権限: データベース ${DB_NAME} の中だけ（接続は TLS 必須）"
if [[ -n "$pw" ]]; then
  echo ".env に入れる値（この画面にしか出ません。全台の .env を同じ値にする）:"
  echo "  DB_USERNAME=${APP_USER}"
  echo "  DB_PASSWORD=${pw}"
else
  echo "ユーザーは作成済みです（パスワードは変えていません。作り直すときは RESET_PASSWORD=true）"
fi
