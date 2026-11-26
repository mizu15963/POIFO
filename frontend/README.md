# POIFO フロントエンド

ポイフォの会員向けサイト。Astro 7 の静的出力を S3＋CloudFront で配信する。
作業ルールは [AGENTS.md](AGENTS.md)（Claude Code は [CLAUDE.md](CLAUDE.md) から読み込む）。

## しくみ

- ページの枠（HTML・CSS）はビルド時に作り、案件・イベント・会員情報・ポイントは **ブラウザから API で取得** して表示する
- API は `https://api.<domain>`（[../backend/](../backend/)）。ログインは LINE ログイン → API がセッション Cookie を発行 → フロントは `credentials: 'include'` で API を呼ぶ
- 案件へ進むボタンは API の `/go/{id}` に飛ばし、API が ASP の計測リンクに会員IDを付けてリダイレクトする

## ページ

画面の仕様: [../docs/specs/screen-spec.md](../docs/specs/screen-spec.md)、機能の仕様: [../docs/specs/functional-spec.md](../docs/specs/functional-spec.md)

| URL | 内容 |
|---|---|
| `/` | トップ（ファーストビュー・おすすめ案件・担当サポート・イベント・特徴・初めての方へ） |
| `/campaigns/` | 案件一覧（カテゴリのタブで絞り込み。`?category=` で共有できる） |
| `/campaigns/detail/?id=` | 案件詳細・「この案件に進む」 |
| `/events/` | イベント一覧 |
| `/events/detail/?id=` | イベント詳細・参加／取り消し（要ログイン）。オープンチャットの URL は参加した会員にだけ出る |
| `/login/` | LINEでログイン（会員登録も兼ねる） |
| `/auth/complete/` | ログイン後の戻り先（`?new=1` で登録完了＋公式LINEの案内） |
| `/auth/error/?reason=` | ログイン失敗 |
| `/mypage/` | マイページ＝ポイント通帳（ポイント・明細／交換履歴／案件の利用履歴のタブ。`?tab=`。要ログイン） |
| `/mypage/points/` | マイページの明細タブへ移る（旧ポイント履歴のブックマーク用） |
| `/mypage/account/` | 登録情報（会員ID・登録日・振込先口座・退会。要ログイン） |
| `/mypage/bank/` | 振込先口座の登録・変更（要ログイン） |
| `/mypage/withdraw/` | ポイント交換＝交換先の一覧（要ログイン） |
| `/mypage/withdraw/detail/?method=` | 交換先の詳細・申請（要ログイン） |
| `/mypage/favorites/` | お気に入りの案件（要ログイン） |
| `/news/` `/news/detail/?id=` | お知らせ一覧・詳細 |
| `/mypage/leave/` | 退会（要ログイン。手続き中の換金申請があるとできない） |
| `/support/` `/guide/` | 担当サポート・はじめての方へ |
| `/terms/` `/privacy/` `/company/` `/contact/` | 準備中ページ |

## 開発

必要なもの: Node.js 22.23 以上。

```sh
cd frontend
cp .env.example .env
npm ci
npm run dev        # http://localhost:4321
npm run build      # 型チェック → dist/ に出力
npm run preview    # dist/ をローカルで確認
```

API を使う画面はバックエンドも起動しておく（[../backend/README.md](../backend/README.md)。`http://api.localhost:8000`）。

## 環境変数（ビルド時に埋め込まれる公開値）

| 変数 | ローカル | 本番 |
|---|---|---|
| `PUBLIC_SITE_URL` | `http://localhost:4321` | `https://<domain>` |
| `PUBLIC_API_BASE_URL` | `http://api.localhost:8000` | `https://api.<domain>` |
| `PUBLIC_LINE_OFFICIAL_URL` | 空でよい | 公式LINEの友だち追加URL |

## デプロイ

main に `frontend/` の変更が入ると、ワークフロー「デプロイ（フロント）」が `dist/` を S3 に同期して CloudFront のキャッシュを削除する。
本番の `PUBLIC_*` は GitHub Variables に置く。詳しくは [../docs/deploy/README.md](../docs/deploy/README.md)。

## エラーページ

| ファイル | 元 | CloudFront で返す場面（`terraform/app/front.tf`） |
| --- | --- | --- |
| `404.html` | `src/pages/404.astro` | S3 に無いパス（403・404）。状態コードは 404。通常のレイアウトで行き先を案内する |
| `500.html` | `src/pages/500.astro` | S3 側の障害（500・502・503・504）。状態コードはそのまま。共通 CSS・API に頼らず単体で表示できるように作っている |
| `maintenance/index.html` | `src/pages/maintenance/index.astro` | CloudFront ではなく、API が 503（メンテナンス中）を返したときに `src/lib/api.ts` がここへ移す |

500 とメンテナンス画面は `src/layouts/StandaloneLayout.astro`（CSS を埋め込んだ単体のレイアウト）を使う。
API・管理画面（Laravel）が HTML で返すエラー画面は `../backend/resources/views/errors/` にある。

## まだやっていないこと

- favicon・OGP 画像（ロゴの正式な書き出し待ち）。いまはヘッダー用にロゴ原本の余白を切り詰めた画像を使っている
- 利用規約・プライバシーポリシーなどの本文
