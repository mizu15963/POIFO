# AWS 環境の構築・更新・削除（Terraform＋GitHub Actions）

POIFO の AWS 環境は Terraform で定義し、GitHub Actions から承認付きで構築・更新・削除する。
AWS の認証はアクセスキーを使わず OIDC（GitHub が発行する一時トークンで IAM ロールに入る）。
構成の考え方は [サーバ構成計画書](https://claude.ai/artifact/Li3ceqNda1GbgupeQTfVNZ) を参照。

> このリポジトリは public。アカウントID・IP アドレス・秘密の値はコミットせず、GitHub の Secrets / Variables に入れる。
> Actions のログとジョブサマリーは誰でも見られるので、ワークフローはアカウントIDを伏せ字にしている。

## 全体像

```text
.github/
  workflows/
    infra-build-destroy.yml  # 構築・削除（Terraform）: 手動実行。plan → 承認 → apply / destroy
    infra-update.yml         # 更新（Terraform）: PR で plan、main に入ったら plan → 承認 → apply
    oidc-whoami.yml          # 接続確認（OIDC whoami）: 何も作らない
  scripts/terraform.sh       # 両ワークフロー共通の実行スクリプト
  dependabot.yml             # アクションと provider の更新 PR（月1回）
terraform/
  bootstrap/  # 手元PCから1回だけ apply（state 用 S3・OIDC・Actions 用ロール）。state は手元
  base/       # 消すと困るもの: DB（Lightsail マネージドデータベース）、DB バックアップ用 S3、バックアップ送信用 IAM ユーザー、Route 53 ホストゾーン
  app/        # 本体: Lightsail、フロント用 S3＋CloudFront、（ドメイン設定後）証明書・API 用 CloudFront・WAF
```

| ワークフロー | いつ使う | 何をする |
|---|---|---|
| 構築・削除（Terraform） | 最初の構築、全部消すとき、作り直すとき | `plan` / `apply` / `destroy` を選んで手動実行。`all` は構築が base → app、削除が app → base |
| 更新（Terraform） | `terraform/` を変更したとき | PR では plan だけ（差分をジョブサマリーに表示）。main にマージされると plan → 承認 → apply。Variables / Secrets だけ変えたときは手動実行 |
| 接続確認（OIDC whoami） | 初回・ロールを変えたとき | OIDC で plan ロールに入れるかを確認 |

AWS に書き込むジョブ（apply / destroy）は、GitHub の Environment `poifo-apply` で承認されるまで止まる。
スマホの GitHub アプリからも承認できる。

**誤削除の防止**: plan / apply で、1台目の Lightsail インスタンス・固定IP・DB・ホストゾーン・バックアップ用バケットを
消す（作り直す）変更が含まれると止まる。これらを消せるのは「構築・削除」の `destroy` だけ。

| ロール | 使う場面 | 権限 |
|---|---|---|
| `poifo-gha-plan` | plan・whoami（main ブランチ、同じリポジトリからの PR） | ReadOnlyAccess |
| `poifo-gha-deploy` | アプリのデプロイ（Environment `poifo-deploy` のジョブだけ） | フロントの S3 同期・CloudFront のキャッシュ削除・バックエンドのリリース置き場への配置だけ（[../deploy/README.md](../deploy/README.md)） |
| `poifo-gha-apply` | apply・destroy（`poifo-apply` の承認後だけ） | Lightsail・CloudFront・WAF・ACM・Route 53（東京と us-east-1 のみ）、`poifo-*` の S3 バケット、`poifo-*` の IAM ユーザー |

## 費用と片付け（先に読む）

| 段階 | 主な費用（月額・税別、1ドル=150円） |
|---|---|
| 段階1: ドメインなし | Lightsail 1GB $7（約1,050円）＋マネージドDB 標準 $15（約2,250円、自動バックアップ込み）＋自動スナップショット（数十円）＋S3 少額。フロントの CloudFront は従量課金だが常時無料枠内 |
| 段階2: ドメインあり（定額プラン加入前） | 上記＋Route 53 ホストゾーン $0.50＋WAF 従量（API 用 $5＋ルール5個 $5、フロント用 $5＋ルール2個 $2、時間割り） |
| 段階2: 定額プラン（Free）加入後 | WAF は定額プランに含まれて $0。合計は約3,400円 |

WAF の従量課金は時間割りなので、段階2の apply の後は早めに定額プランへ加入する（手順6）。

片付けの順番:
1. CloudFront の定額プランを解約する（加入中のディストリビューションは削除できない）
2. 「構築・削除」→ `destroy` / `app`（削除前に Lightsail の手動スナップショットを自動で取る）
3. 必要ならデータを退避してから、バックアップ用バケットの中身を空にし、IAM ユーザー `poifo-backup-writer` のアクセスキーを削除して、`destroy` / `base`（DB は最終スナップショット `poifo-db-final-<DB作成日時>` を残して消える）
4. 手元PCで `terraform/bootstrap` を `terraform destroy`
5. Terraform 管理外のもの（サーバの削除前スナップショット、DB の最終スナップショット）を Lightsail コンソールで削除

## 0. 事前準備（リポジトリ管理者の作業）

**main ブランチを作り、デフォルトブランチにする。** 理由は2つ。
- 手動実行（workflow_dispatch）の「Run workflow」ボタンは、デフォルトブランチにあるワークフローにしか出ない
- plan ロールは `refs/heads/main` からのトークンしか受け付けない（Environment も main だけに絞る）

GitHub の画面で: Branches → New branch で `main` を作成 → Settings → General → Default branch を `main` に変更。

## 1. 手元PCの準備（Windows PowerShell）

AWS CLI・Terraform（1.10 以上）・GitHub CLI（gh）を入れる。

```powershell
winget install Amazon.AWSCLI
winget install Hashicorp.Terraform
winget install GitHub.cli
gh auth login
```

AWS には IAM Identity Center のユーザー（AdministratorAccess の権限セット）で入る。プロファイルは1回だけ作る。

```powershell
aws configure sso --profile poifo-admin   # クライアントのアカウントの SSO start URL とリージョン ap-northeast-1 を入力
$env:AWS_PROFILE = "poifo-admin"            # PowerShell を開くたびに
aws sso login --profile poifo-admin         # 期限切れのとき
aws sts get-caller-identity                 # AWSReservedSSO_AdministratorAccess_... なら OK
```

AWS アカウントはクライアントが払い出したもの（費用もクライアント負担）。別案件のプロファイルを流用せず、
apply の前に毎回 `aws sts get-caller-identity` の Account がクライアントのアカウントIDであることを確かめる。

📝 学習メモ: Identity Center のユーザーで入ると、アクセスキーを PC に置かずに済む（数時間で切れる一時的な認証情報）。

## 2. bootstrap を手元PCで apply（1回だけ）

GitHub のリポジトリIDを調べる。2026-07-15 以降に作ったリポジトリは、OIDC トークンの `sub` が ID 付き形式になる。

```powershell
gh api repos/<OWNER>/<REPO> --jq '{id, owner_id: .owner.id, created_at}'
```

```powershell
cd terraform/bootstrap
copy terraform.tfvars.example terraform.tfvars   # github_repo / github_owner_id / github_repo_id を埋める
terraform init
terraform plan
terraform apply
```

作られるもの: state 用バケット `poifo-tfstate-<アカウントID>`、OIDC プロバイダ、ロール `poifo-gha-plan`・`poifo-gha-apply`・`poifo-gha-deploy`。
費用はほぼ0円（S3 の数KB）。

- OIDC プロバイダはアカウントに1つだけ。同じアカウントに既にあれば `create_github_oidc_provider = false`
- `terraform.tfvars` と bootstrap の state（`terraform.tfstate`）は .gitignore 済み。state は手元PCで保管する（無くすと bootstrap を Terraform で消せなくなる）

## 3. GitHub に登録

### Secrets と Variables

bootstrap の出力を Secrets に入れる（アカウントIDを含むので Variables ではなく Secrets）。
**パイプ（`|`）で渡さない**（Windows PowerShell は先頭に BOM を付け、`InvalidBucketName` などで失敗する）。

```powershell
gh secret set TF_STATE_BUCKET    --body (terraform output -raw tf_state_bucket)
gh secret set AWS_PLAN_ROLE_ARN  --body (terraform output -raw plan_role_arn)
gh secret set AWS_APPLY_ROLE_ARN --body (terraform output -raw apply_role_arn)
gh secret set AWS_DEPLOY_ROLE_ARN --body (terraform output -raw deploy_role_arn)   # アプリのデプロイ用
gh variable set PROJECT --body "poifo"
```

段階1で使うもの（`DB_MASTER_PASSWORD` は必須、ほかはなくても動く）:

| 名前 | 種類 | 値の例 | 用途 |
|---|---|---|---|
| `DB_MASTER_PASSWORD` | Secrets | 32文字のランダム値 | マネージドDB のマスターユーザー（`dbadmin`）のパスワード。16〜41文字（Lightsail の上限）、`/` `"` `@` は使えない。Laravel 用には権限を絞った別ユーザーを DB 内で作る |
| `SSH_PUBLIC_KEY` | Variables | `ssh-ed25519 AAAA... poifo` | Lightsail に入れる公開鍵。空なら Lightsail のデフォルト鍵。**サーバ作成時にしか反映されない** |
| `SSH_ALLOWED_CIDRS` | Secrets | `["203.0.113.10/32"]` | SSH を許可する運用者の IP。空でも Lightsail コンソールのブラウザ SSH は使える |

段階2（独自ドメイン）で使うもの:

| 名前 | 種類 | 値の例 | 用途 |
|---|---|---|---|
| `DOMAIN_NAME` | Variables | `example.jp` | サイトのドメイン。base がホストゾーンを作る |
| `ENABLE_CUSTOM_DOMAIN` | Variables | `true` | ネームサーバの委任が済んでから `true` にする |
| `ORIGIN_VERIFY_SECRET` | Secrets | 48文字のランダム値 | CloudFront → Lightsail の秘密ヘッダー。nginx にも同じ値を設定 |
| `ADMIN_ALLOWED_CIDRS` | Secrets | `["203.0.113.10/32"]` | admin. を許可する IP。空なら IP 制限ルールなし |
| `POSTBACK_ALLOWED_CIDRS` | Secrets | `["<ASPの送信元IP>/32", ...]` | ポストバック受信パス（`/postback/`）を許可する ASP の送信元 IP。値は ASP 資料を参照（資料は社外秘なのでリポジトリに書かない） |
| `WAF_COUNT_MODE` | Variables | `true` / `false` | AWS マネージドルールを検知のみにするか。既定 `true`。提携先とのテストで誤検知がないのを確かめてから `false` |

リストの値（`["..."]`）は、Windows PowerShell から `gh` に渡すと `"` が消えることがあるので、
GitHub の画面（Settings → Secrets and variables → Actions）で貼り付けて登録する。

`ORIGIN_VERIFY_SECRET` の作り方（PowerShell）:

```powershell
$b = New-Object byte[] 24; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
$s = ($b | ForEach-Object { $_.ToString("x2") }) -join ""
gh secret set ORIGIN_VERIFY_SECRET --body $s
$s   # nginx の設定に使う。パスワードマネージャーに保存
```

`DB_MASTER_PASSWORD` は Lightsail の上限（41文字まで）に合わせて16バイト（32文字の16進）で作る:

```powershell
$b = New-Object byte[] 16; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
$p = ($b | ForEach-Object { $_.ToString("x2") }) -join ""
gh secret set DB_MASTER_PASSWORD --body $p
$p   # パスワードマネージャーに保存。Terraform の state（非公開の S3）にも残る
```

### DB のプランと MySQL バージョンの確認（初回 apply 前に1回）

`db_bundle_id = micro_2_0`（標準 $15）と `db_blueprint_id = mysql_8_4` が東京リージョンで使えるかを確かめる。
一覧になければ、`terraform/base/variables.tf` の既定値を一覧にある ID に変える。

```powershell
aws lightsail get-relational-database-bundles --region ap-northeast-1 --query "bundles[?isActive].[bundleId,price,ramSizeInGb]" --output table
aws lightsail get-relational-database-blueprints --region ap-northeast-1 --query "blueprints[?engine=='mysql'].[blueprintId,engineVersion]" --output table
```

### Environment

Settings → Environments → New environment → `poifo-apply`
- Required reviewers: 自分
- Deployment branches and tags: Selected branches → `main` のみ

名前は bootstrap の `apply_environment` とワークフローの `environment:` と同じにする。

## 4. actions.lock を作る

使うアクションのコミットを固定する（タグは後から付け替えられるため）。

```powershell
gh extension install github/gh-actions-lock   # 初回だけ
gh actions-lock                               # .github/workflows/actions.lock を生成 → コミット
```

- `uses:` を追加・削除したら `gh actions-lock`、バージョンを上げたら `gh actions-lock --relock`
- 生成ファイルは手で編集しない。リポジトリ設定の **Require lockfile** を有効にすると、lock に無いアクションは動かない

## 5. 段階1: ドメインなしで構築

1. Actions → **接続確認（OIDC whoami）** → Run workflow。`sub` と `assumed-role/poifo-gha-plan/...` が出れば OK
2. **構築・削除（Terraform）** → action `plan`、stack `all` → サマリーで差分を確認
3. action `apply`、stack `all` → 承認待ちで Approve

できるもの:
- base: マネージドDB `poifo-db`（MySQL 8.4、標準 1GB、外部非公開、自動バックアップ毎日 3:00 JST・7日分）、
  バックアップ用バケット `poifo-backup-<アカウントID>`、IAM ユーザー `poifo-backup-writer`
- app: Lightsail `poifo-api`（Ubuntu 24.04、1GB、自動スナップショット毎日 3:00 JST）、固定IP、ファイアウォール（443・80・22）、
  フロント用バケットと CloudFront（`https://xxxx.cloudfront.net`）

確認: ジョブサマリーの outputs に `front_url` と `db_endpoint`（Laravel の `DB_HOST`）が出る。
DB は同じリージョンの Lightsail インスタンスからだけ接続できる（PC から直接はつながらない）。固定IP はサマリーに出さないので Lightsail コンソールか、
手元PCで `terraform output -raw static_ip`（下の「手元PCから state を見る」）で確認する。

この段階でサーバの初期設定（nginx・PHP・Laravel）とアプリ開発を進められる。

📝 学習メモ: DB をマネージドデータベースに分けたので、サーバを作り直しても DB は残る。
DB のバックアップ・OS 更新は Lightsail が行う。S3 への mysqldump は「アカウントの外へ持ち出せる形の控え」として続ける。

## 6. 段階2: 独自ドメインを付ける

1. Variables に `DOMAIN_NAME` を登録 → **構築・削除** → `apply` / `base`。outputs の `name_servers`（4つ）を控える
2. ドメインの管理元（クライアント側）で、ネームサーバをこの4つに変更する（サブドメインだけ使う場合はそのサブドメインの NS レコードを委任）。
   反映確認: `Resolve-DnsName example.jp -Type NS`
3. Secrets に `ORIGIN_VERIFY_SECRET`、必要なら `ADMIN_ALLOWED_CIDRS`・`POSTBACK_ALLOWED_CIDRS` を登録
4. Variables の `ENABLE_CUSTOM_DOMAIN` を `true` → **構築・削除** → `apply` / `app`
   - 証明書（`example.jp`・`api.`・`admin.`）、API 用 CloudFront、WAF 2つ、DNS レコード（`origin.` は固定IP）ができる
   - 証明書の DNS 検証で数分かかる。ネームサーバが委任されていないと、ここで待ち続けて失敗する
5. サーバで `origin.example.jp` の Let's Encrypt 証明書を取り、nginx に秘密ヘッダーの確認と api / admin の振り分けを入れる（下の nginx の例）
6. **CloudFront の定額プラン（Free）に加入する**（Terraform では扱えないのでコンソールで）
   - CloudFront コンソールで、フロント用（`poifo front`）と API 用（`poifo api/admin`）のそれぞれに Free プランを選ぶ。Web ACL は Terraform が作った `poifo-front` / `poifo-api` を使う
   - Free プランはアカウントにつき3つまで、ルールは5個まで、ログ出力やカスタムのキャッシュ・オリジンリクエストポリシーは使えない（この構成はその範囲に収めてある）
   - 加入後に **更新（Terraform）** を手動実行し、plan が「No changes」になることを確認する。差分が出たら（特に `web_acl_id`）、apply せずに相談する
   - AWS の無料プラン（Free Tier プラン）のアカウントでは定額プランに加入できない

📝 学習メモ: Lightsail には AWS WAF を直接付けられないので、CloudFront を入口にして WAF を付け、
CloudFront を通らない直接アクセスは nginx が秘密ヘッダーの有無で拒否する。
CloudFront からオリジンへの Host は `origin.example.jp` になるため、閲覧者がアクセスしたホスト名は
CloudFront Function が `x-poifo-viewer-host` ヘッダーに入れて渡す。

### nginx の例（オリジン側）

```nginx
# /etc/nginx/conf.d/origin-verify.conf
map $http_x_origin_verify $origin_ok {
    default 0;
    "<ORIGIN_VERIFY_SECRET の値>" 1;
}

server {
    listen 443 ssl;
    http2 on;
    server_name origin.example.jp;
    ssl_certificate     /etc/letsencrypt/live/origin.example.jp/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/origin.example.jp/privkey.pem;

    # CloudFront（＝WAF）を通っていないリクエストは拒否
    if ($origin_ok = 0) { return 403; }

    # 閲覧者のアクセス先（api. / admin.）は $http_x_poifo_viewer_host で分かる。
    # CloudFront Function が必ず上書きするので偽装できない。Laravel 側でこの値を見て
    # 管理画面のルートは admin. からのアクセスだけに限る（api. からは届かないようにする）
    root /var/www/poifo/current/public;   # デプロイで切り替わるリンク（docs/deploy/README.md）
    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param POIFO_VIEWER_HOST $http_x_poifo_viewer_host;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}

server {
    listen 80;
    server_name origin.example.jp;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}
```

細かい設定はサーバ構築の手順書で詰める。管理画面は IP 制限（WAF と nginx の二重）と二要素認証をかける。

## 7. 日々の更新

1. ブランチで `terraform/` を変更して PR を作る → **更新（Terraform）** が plan を実行し、ジョブサマリーに差分が出る
2. 差分を確認して main にマージ → 自動で plan → 変更があれば承認待ち → Approve で apply
3. Variables / Secrets だけを変えたとき（例: `ADMIN_ALLOWED_CIDRS` の変更、`WAF_COUNT_MODE` を `false` に）は、**更新（Terraform）** を手動実行

1台目のサーバ・固定IP・DB・ホストゾーン・バックアップ用バケットを消す（作り直す）変更は止まる（台数を減らして2台目以降が消えるのは止めない）。
例えば `lightsail_bundle_id`（プラン）や `lightsail_blueprint_id`（OS）、`db_bundle_id` を変えると作り直しになるので止まる。

### サーバのプランを上げる（1GB → 2GB）

Lightsail はプラン変更＝別インスタンスへの作り直しになる。DB は別なので残るが、サーバ上の設定・アップロードファイルを失わないように:
1. Lightsail コンソールで手動スナップショットを取る
2. スナップショットから新しいプランのインスタンスを作り、動作を確認
3. Terraform の state を新インスタンスに付け替える（`terraform state rm` → `terraform import`）か、相談する

DB のプランを上げるときも同じく作り直しになる（DB のスナップショットから新しいプランで作る）。
MySQL のマイナー更新は Lightsail のメンテナンス時間（月曜 4:00 JST）に自動で入る。

### API サーバを増やす（ロードバランサー）

負荷が増えたら、手動で台数を増やし、API サーバの直前に Lightsail ロードバランサー（LB）を置く。
アプリは状態（セッション・キャッシュ・キュー・ロック）を DB に持つので、どの台に振り分けられても同じように動く。

```text
CloudFront（WAF）→ https://origin.<domain> ─┬ LB なし: 1台目の固定 IP
                                            └ LB あり: LB（HTTPS を終端）→ http://各サーバ:80
```

CloudFront のオリジンは最初から `origin.<domain>` なので、LB を入れる・外すときは DNS の向き先が変わるだけ。

| Variables | 既定 | 内容 |
|---|---|---|
| `API_SERVER_COUNT` | `1` | API サーバの台数（1〜5）。2台目以降は `poifo-api-2`、`poifo-api-3`…。デプロイもこの台数分の結果を待つ |
| `ENABLE_LOAD_BALANCER` | `false` | `true` で LB（$18/月、約2,700円）を作る。独自ドメインが必要。2台以上なら必須 |

費用は1台増やすごとに $7/月（約1,050円）、LB が $18/月。

手順:
1. Variables の `ENABLE_LOAD_BALANCER` を `true`、`API_SERVER_COUNT` を `2` にして、**更新（Terraform）** を手動実行 → 承認
   - LB の証明書（`origin.<domain>`）の検証に数分かかる。`certificate_attachment` が失敗したら、少し待って再実行する
2. 新しいサーバを1台目と同じように準備する（[../deploy/README.md](../deploy/README.md) の「サーバ（1回だけ）」）。
   **`.env` は全台同じ内容にする**（`APP_KEY` は口座番号の暗号化に使うので、違うと復号できない）。
   deploy agent の設定は `SERVER_NAME=api-2`、`MIGRATE=false`（migrate は1台目だけが行う）
3. nginx を LB 用に変える: LB からは HTTP（80番）で届くので、80番でもアプリを動かす（秘密ヘッダーの確認はそのまま）。
   ヘルスチェック（`/up`）は秘密ヘッダーなしで来るので、そのパスだけヘッダーなしでも通す
4. 「デプロイ（バックエンド）」を手動実行し、全台が同じリリースになることを確認する
5. Lightsail コンソールで LB のターゲットがすべて「正常」になっていることを確認する

減らすときは `API_SERVER_COUNT` を下げて更新を実行する（番号の大きいサーバから消える）。1台に戻したら `ENABLE_LOAD_BALANCER` も `false` にできる。

## 8. 削除

1. 定額プランに加入しているディストリビューションは、先にコンソールで解約する（Free は即時解約）
2. **構築・削除** → action `destroy`、stack `app`、confirm に `app` と入力 → 承認
   - 削除前に Lightsail の手動スナップショット（`poifo-api-before-destroy-日時`）を自動で取る。自動スナップショットはインスタンスと一緒に消えるため
   - 手動スナップショットは自分で消すまで残り、$0.05/GB・月かかる
3. base も消すときは、バックアップ用バケットを空にし、`poifo-backup-writer` のアクセスキーを削除してから、`destroy` / `base`（confirm に `base`）。
   DB は最終スナップショット（`poifo-db-final-<DB作成日時>`）を残してから消える。名前の日時は削除日ではなく DB を作った日時。
   ホストゾーンを消すとネームサーバが変わるので、再構築時はドメイン側の設定もやり直しになる

## うまくいかない時

| エラー | 原因と対処 |
|---|---|
| `Not authorized to perform sts:AssumeRoleWithWebIdentity` | sub の不一致。whoami の `sub` と bootstrap の `github_repo` / `github_owner_id` / `github_repo_id` / `apply_environment` を比べる。main 以外から実行していないか |
| `Could not load credentials from any providers` | Secrets 名の打ち間違い、または fork からの PR |
| `InvalidBucketName`（先頭に BOM） | PowerShell のパイプで Secret を登録した。`--body` で登録し直す |
| `AccessDenied ... <action>` | apply ロールの権限不足。bootstrap の `apply_regional_actions` などに足して手元PCで再 apply |
| ⛔ 削除・作り直しを含むため止めました | 上の「誤削除の防止」。変数の変更でサーバが作り直しにならないか確認する |
| ACM 証明書の検証が終わらない | ネームサーバの委任が済んでいない（手順6の2） |
| `DB_MASTER_PASSWORD ... を登録してください` | base の apply には DB のマスターパスワードが必要 |
| `ORIGIN_VERIFY_SECRET ... を登録してください` | 独自ドメイン有効時は32文字以上の秘密ヘッダーが必要 |
| `Error acquiring the state lock` | 別の実行中、または中断でロックが残った。他に動いていないのを確かめて手元PCで `terraform force-unlock <ID>` |
| ディストリビューションを削除できない | 定額プランに加入中。コンソールで解約してから destroy |
| lockfile 関連のエラー | `uses:` を変えたのに actions.lock を更新していない → `gh actions-lock` |

### 手元PCから state を見る（output 確認・緊急時）

```powershell
cd terraform/app   # または terraform/base
terraform init -reconfigure `
  -backend-config="bucket=poifo-tfstate-<アカウントID>" `
  -backend-config="key=app/terraform.tfstate" `
  -backend-config="region=ap-northeast-1" `
  -backend-config="use_lockfile=true"
terraform output
terraform output -raw static_ip
```

## まだやっていないこと

- アプリのデプロイは [../deploy/README.md](../deploy/README.md)（app スタックにリリース置き場 `poifo-deploy-<アカウントID>` とサーバ用 IAM ユーザー `poifo-deploy-agent` がある）
- サーバの初期設定（nginx・PHP・バックアップ cron・監視）と、DB 内のアプリ用ユーザー作成は Terraform の対象外。手順書を別に用意する
- Lightsail のメトリクスアラーム（CPU・ステータスチェック）の通知先メールは未設定
