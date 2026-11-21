# frontend/ の作業ルール（AI エージェント・開発者共通）

POIFO（ポイフォ）の会員向けサイト。Astro 7 の **静的出力** を S3＋CloudFront で配信する。
API は別ホスト（`https://api.<domain>`、[../backend/](../backend/)）にあり、ブラウザから Cookie 付きで呼ぶ。
画面の仕様は [../docs/specs/top-page-spec.md](../docs/specs/top-page-spec.md) とそのレビュー（[../docs/reviews/](../docs/reviews/)）、色は [../docs/brand/README.md](../docs/brand/README.md)。

## 絶対に守ること

- **このリポジトリは public。** 秘密の値を書かない。`PUBLIC_` で始まる環境変数はビルド結果に埋め込まれ誰でも見られるので、URL などの公開してよい値だけにする。API キー類はフロントに置かない（必要ならバックエンドで扱う）。
- **静的サイトのまま保つ。** SSR・アダプター（`output: 'server'`）・サーバ側の処理を入れない。S3 に置けるファイルだけを出力する。
- **変わるデータはビルドに埋め込まない。** 案件・イベント・会員情報・ポイントは、ブラウザから API（`src/lib/api.ts`）で取得して表示する。管理画面での更新を再ビルドなしで反映するため。
- **API から来た文字列は必ずエスケープする。** HTML に入れるときは `escapeHtml()`、URL を属性に入れるときは `safeUrl()`（`src/lib/format.ts`）を通す。`innerHTML` に生の値を入れない。
- **API は `src/lib/api.ts` 経由で呼ぶ。** 直接 `fetch` しない（`credentials: 'include'`・CSRF の扱いをまとめてあるため）。POST などの更新系は `withCsrf()` を通す。
- **案件のリンクは `campaignGoUrl()`（API の `/go/{id}`）を使う。** ASP の計測リンクをフロントで組み立てない（会員IDを付けるのはバックエンドの役目）。
- **ロゴは `public/images/` の書き出し済み画像を使う。** 描き直したり、フォントで似せたりしない。
- **キャラクター（ポイフォックス）は `src/components/Mascot.astro` で `public/images/mascot/` の書き出し済み WebP を使う。** 描き直し・色や形の加工・別ポーズの自作はしない。

## URL と画面

- URL はディレクトリ形式で末尾スラッシュあり（`/campaigns/`）。CloudFront Function が `/campaigns/` → `/campaigns/index.html` に読み替える。
- 詳細ページは ID ごとのファイルを作らず、クエリで受ける（`/campaigns/detail/?id=123`、`/events/detail/?id=123`）。
- ログインが必要なページは `<BaseLayout requireLogin>`。未ログインなら `/login/` へ移動する。会員向け・認証まわりのページは `noindex`。
- ログイン状態での出し分けは `data-show="guest"` / `data-show="member"`（BaseLayout が `/v1/me` を見て body に `data-auth` を付ける）。ページのスクリプトで会員情報が要るときは `whenMe()`（`src/lib/session.ts`）を使い、`/v1/me` を何度も呼ばない。

## デザイン

- スマホ優先。まず幅 390px 前後で崩れないことを確かめてから PC 幅を見る。
- 色は `src/styles/global.css` の CSS 変数だけを使う。ティファニーブルー（`--color-brand`）はアクセントに留め、白文字を載せない。主要 CTA は濃色背景（`.btn`）か LINE の緑（`.btn--line`）。
- ポイントの状態（判定中・確定・否認）は色だけでなく文字でも示す。
- 太すぎる見出し・強い影は使わない（`font-weight` は 600 まで）。
- キャラクターは文章の横に添える程度にし、1セクションに1体まで。案件カードなど情報の一覧には入れない。文章で意味が伝わる場所では `alt=""`（装飾扱い）にする。

## コーディング

- TypeScript（strict）。コメントは日本語で、なぜそうするかを書く。
- 共通部品は `src/components/`、API・整形・描画の関数は `src/lib/`。ページ固有のスタイルはそのページの `<style>` に置く。
- 依存パッケージは必要最小限にする（UI フレームワークは入れていない。入れる前に相談する）。

## 確認方法

変更したら必ず実行し、通ってからコミットする。

```sh
npm ci
npm run build     # astro check（型チェック）→ astro build。dist/ に出力
```

画面を変えたら、バックエンドをローカルで起動（`../backend/README.md`）してから `npm run dev` で実際に表示を確かめる。
