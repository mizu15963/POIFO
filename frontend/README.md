# POIFO フロントエンド

ポイフォの会員向けサイト。Astro 7 の静的出力を S3＋CloudFront で配信する。
作業ルールは [AGENTS.md](AGENTS.md)（Claude Code は [CLAUDE.md](CLAUDE.md) から読み込む）。

## しくみ

- ページの枠（HTML・CSS）はビルド時に作り、案件・イベント・会員情報・ポイントは **ブラウザから API で取得** して表示する
- API は `https://api.<domain>`（[../backend/](../backend/)）。ログインは LINE ログイン → API がセッション Cookie を発行 → フロントは `credentials: 'include'` で API を呼ぶ
- 案件へ進むボタンは API の `/go/{id}` に飛ばし、API が ASP の計測リンクに会員IDを付けてリダイレクトする

## ページ

| URL | 内容 |
|---|---|
| `/` | トップ（ファーストビュー・おすすめ案件・担当サポート・イベント・特徴・初めての方へ） |
| `/campaigns/` | 案件一覧 |
| `/campaigns/detail/?id=` | 案件詳細・「この案件に進む」 |
| `/events/` | イベント一覧 |
| `/events/detail/?id=` | イベント詳細・オープンチャットへの参加ボタン |
| `/login/` | LINEでログイン（会員登録も兼ねる） |
| `/auth/complete/` | ログイン後の戻り先（`?new=1` で登録完了＋公式LINEの案内） |
| `/auth/error/?reason=` | ログイン失敗 |
| `/mypage/` | ポイント（利用可能／判定中）・メニュー（要ログイン） |
| `/mypage/points/` | ポイント履歴（要ログイン） |
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
`404.html` は CloudFront のカスタムエラーページに使える。

## まだやっていないこと

- ポイント交換（口座登録・換金申請）の画面
- 退会の画面
- favicon・OGP 画像（ロゴの正式な書き出し待ち）。いまはヘッダー用にロゴ原本の余白を切り詰めた画像を使っている
- 利用規約・プライバシーポリシーなどの本文
