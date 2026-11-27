// キャラクター「ポイフォックス」の画像の大きさと、ブラウザ側で HTML を作るときの <img>。
// Astro のテンプレートでは src/components/Mascot.astro を使い、スクリプトで空・エラー表示を作るときはこちらを使う。
// 画像は public/images/mascot/ の書き出し済み WebP（透過・長辺480px。横長のメンテナンス用だけ640px）。
export const MASCOT_SIZES = {
  wave: [426, 480], // 手を振る（あいさつ）
  cheer: [480, 470], // 座って応援
  point: [454, 480], // 指さし
  line: [480, 463], // 「LINEで相談」
  get: [437, 480], // 「ポイントGET!」
  event: [480, 459], // 「イベント参加」
  guide: [480, 418], // チェックリストで説明
  sorry: [447, 480], // 謝る（エラー用）
  maintenance: [640, 472], // 整備士（メンテナンス画面用、横長で長辺640px）
  welcome: [457, 480], // Pコインを持ってお出迎え（S07 会員登録完了）
  peek: [480, 292], // 縁につかまってのぞき込む（2026-11-27 納品。下端が手で切れた絵。S01 のキービジュアルとページの一番下）
} as const;

export type MascotPose = keyof typeof MASCOT_SIZES;

/** 置き方の目安（docs/brand/README.md「キャラクターの置き方」）: 見出しの横 72px、空・完了・エラー 120px */
export const MASCOT_WIDTH = { heading: 72, state: 120 } as const;

export function mascotHeight(pose: MascotPose, width: number): number {
  const [w, h] = MASCOT_SIZES[pose];
  return Math.round((width * h) / w);
}

/** 装飾として添える画像（文章で意味が伝わる場所に置くので alt は空） */
export function mascotImg(pose: MascotPose, width: number = MASCOT_WIDTH.state): string {
  return `<img src="/images/mascot/poifox-${pose}.webp" width="${width}" height="${mascotHeight(pose, width)}" alt="" loading="lazy" decoding="async" class="mascot">`;
}
