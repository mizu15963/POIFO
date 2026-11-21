#!/usr/bin/env bash
# GitHub Actions から呼ぶ Terraform 実行スクリプト（構築・削除／更新の両ワークフローで共通）
#
#   terraform.sh plan         <stack>  [--guard]   確認のみ（plan ロール。state に書かないので -lock=false）
#   terraform.sh plan-destroy <stack>              削除の確認のみ
#   terraform.sh apply        <stack>  [--guard]   構築・変更（apply ロール）
#   terraform.sh destroy      <stack>              削除（apply ロール）
#
# --guard: plan / apply で、1台目のサーバ（Lightsail）・固定IP・DB・ホストゾーン・DBバックアップ用バケットを
#          （2台目以降のサーバは台数を減らすと消えるのが正しいので止めない）
#          消す・作り直す変更を止める（plan-destroy / destroy では無視）。
#          これらを消すのは destroy を明示したときだけにする。
#
# 必要な環境変数: TF_STATE_BUCKET, AWS_REGION（TF_VAR_* はワークフローで設定）
set -euo pipefail

cmd=$1
stack=$2
guard=${3:-}
dir="terraform/${stack}"

: "${TF_STATE_BUCKET:?TF_STATE_BUCKET が未設定}"
: "${AWS_REGION:?AWS_REGION が未設定}"

summary() { printf '%s\n' "$@" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"; }
output() { printf '%s\n' "$@" >> "${GITHUB_OUTPUT:-/dev/null}"; }

# public リポジトリではログ・サマリーを誰でも見られるので、アカウントIDを伏せる
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
echo "::add-mask::${ACCOUNT_ID}"
mask() { sed "s/${ACCOUNT_ID}/************/g"; }

tf() { terraform -chdir="${dir}" "$@"; }
trap 'rm -f plan.log plan_raw.log "${dir}/tfplan"' EXIT

echo "::group::terraform init (${stack})"
tf init -input=false -no-color \
  -backend-config="bucket=${TF_STATE_BUCKET}" \
  -backend-config="key=${stack}/terraform.tfstate" \
  -backend-config="region=${AWS_REGION}" \
  -backend-config="use_lockfile=true" | mask
echo "::endgroup::"

case "${cmd}" in
  plan | plan-destroy)
    tf fmt -check -diff -no-color
    tf validate -no-color
    lock="-lock=false"
    ;;
  apply | destroy)
    lock="-lock=true"
    ;;
  *)
    echo "不明なコマンド: ${cmd}" >&2
    exit 1
    ;;
esac

# DB のプランと MySQL のバージョンが東京リージョンで使えるか、DB を作る前に確かめる（base の plan / apply のとき）。
# 作成済みなら確かめない（後から売り止めになったプランでも、動いている DB の更新は止めない）
check_db_ids() {
  tf state list 2>/dev/null | grep -qx 'aws_lightsail_database.main' && return 0
  # 値は TF_VAR_ があればそれ、なければ variables.tf の既定値
  var_value() {
    local name=$1 env="TF_VAR_$1"
    if [[ -n "${!env:-}" ]]; then echo "${!env}"; return; fi
    awk -v n="\"${name}\"" '$1=="variable" && $2==n {f=1} f && $1=="default" {gsub(/"/,"",$3); print $3; exit}' "${dir}/variables.tf"
  }
  local bundle blueprint bundles blueprints
  bundle=$(var_value db_bundle_id)
  blueprint=$(var_value db_blueprint_id)
  bundles=$(aws lightsail get-relational-database-bundles --region "${AWS_REGION}" \
    --query 'bundles[?isActive].[bundleId,price,ramSizeInGb]' --output text)
  blueprints=$(aws lightsail get-relational-database-blueprints --region "${AWS_REGION}" \
    --query "blueprints[?engine=='mysql'].[blueprintId,engineVersion]" --output text)
  local ng=()
  awk -v id="${bundle}" '$1==id {f=1} END {exit !f}' <<< "${bundles}" || ng+=("プラン db_bundle_id = ${bundle}")
  awk -v id="${blueprint}" '$1==id {f=1} END {exit !f}' <<< "${blueprints}" || ng+=("MySQL のバージョン db_blueprint_id = ${blueprint}")
  if [[ ${#ng[@]} -gt 0 ]]; then
    summary "## ❌ DB の設定が ${AWS_REGION} で使えません" \
      "$(printf -- '- %s\n' "${ng[@]}")" \
      "" "terraform/base/variables.tf の既定値を、下の一覧にある ID に変えて PR を出してください。" \
      "" "### 使えるプラン（ID・月額ドル・メモリGB）" '```' "${bundles}" '```' \
      "### 使える MySQL（ID・バージョン）" '```' "${blueprints}" '```'
    echo "::error::DB の設定が ${AWS_REGION} で使えません: ${ng[*]}（一覧はジョブサマリー）"
    exit 1
  fi
  echo "DB の設定を確認しました: ${bundle} / ${blueprint}"
}
if [[ "${stack}" == "base" && ( "${cmd}" == "plan" || "${cmd}" == "apply" ) ]]; then
  check_db_ids
fi

destroy_flag=""
if [[ "${cmd}" == "plan-destroy" || "${cmd}" == "destroy" ]]; then
  destroy_flag="-destroy"
fi

# -detailed-exitcode: 0=変更なし 1=エラー 2=変更あり
set +e
tf plan -input=false -no-color ${lock} ${destroy_flag} -detailed-exitcode -out=tfplan > plan_raw.log 2>&1
code=$?
set -e
mask < plan_raw.log > plan.log
rm -f plan_raw.log
cat plan.log
if [[ ${code} -eq 1 ]]; then
  summary "## ❌ ${stack}: plan 失敗" '```' "$(tail -n 40 plan.log)" '```'
  exit 1
fi

if [[ ${code} -eq 2 ]]; then
  output "${stack}_changed=true"
  output "changed=true"
else
  output "${stack}_changed=false"
fi

summary "## ${stack}: ${cmd}" '```' "$(grep -E '^(Plan:|No changes|Changes to Outputs)' plan.log || true)" '```'
summary "<details><summary>plan 全文（${stack}）</summary>" "" '```' "$(cat plan.log)" '```' "</details>" ""

if [[ ${code} -eq 2 && "${guard}" == "--guard" && ("${cmd}" == "plan" || "${cmd}" == "apply") ]]; then
  # 消すと DB やドメイン設定を失うリソース
  blocked=$(tf show -json tfplan | jq -r '
    .resource_changes[]?
    | select(.change.actions | index("delete"))
    | select(
        (.type | IN("aws_lightsail_static_ip", "aws_lightsail_database", "aws_route53_zone"))
        or (.address | IN("aws_lightsail_instance.api[0]", "aws_lightsail_instance.api", "aws_s3_bucket.backup"))
      )
    | .address')
  if [[ -n "${blocked}" ]]; then
    summary "### ⛔ 削除・作り直しを含むため止めました（${stack}）" '```' "${blocked}" '```' \
      "サーバ・DB を消すとデータが失われます。本当に必要なら docs/infra/README.md の手順に沿って、「構築・削除」ワークフローの destroy → apply で行ってください。"
    echo "::error::削除・作り直しを含む変更です: ${blocked//$'\n'/, }"
    exit 1
  fi
fi

if [[ "${cmd}" == "apply" || "${cmd}" == "destroy" ]]; then
  if [[ ${code} -eq 0 ]]; then
    echo "変更なし"
  else
    tf apply -input=false -no-color tfplan | mask
  fi
  if [[ "${cmd}" == "apply" ]]; then
    # 接続先（作り直すと CloudFront のアドレスが変わるので、毎回ここに出す）
    if [[ "${stack}" == "app" ]]; then
      summary "## 接続先"
      for name_label in "front_url:トップ画面" "api_url:API" "admin_url:管理画面"; do
        url=$(tf output -raw "${name_label%%:*}" 2>/dev/null || true)
        [[ -n "${url}" && "${url}" != "null" ]] && summary "- ${name_label#*:}: ${url}"
      done
    fi
    summary "## outputs: ${stack}" '```' "$(tf output -no-color | mask)" '```'
  fi
fi
