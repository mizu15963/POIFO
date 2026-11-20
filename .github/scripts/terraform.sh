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
    summary "## outputs: ${stack}" '```' "$(tf output -no-color | mask)" '```'
  fi
fi
