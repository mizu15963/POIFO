// ログイン中トップの「カテゴリから探す」のタイルに添えるアイコン（2026-11-28 納品の見本 member-top/02 に寄せた線のアイコン）。
// カテゴリ名は ASP（GF Rewards）の値そのままなので、名前に含まれる言葉で選び、知らない名前は共通のアイコンにする。
// 色は currentColor（CSS 側で指定）。装飾なので aria-hidden。
const ICONS: Record<string, string> = {
  // ダウンロード（アプリインストール）
  download: '<path d="M12 4v10"/><path d="m7.5 10 4.5 4.5 4.5-4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
  // ゲーム機（アプリの条件達成）
  game: '<rect x="2.5" y="7" width="19" height="11" rx="5.5"/><path d="M7.5 10.5v4M5.5 12.5h4"/><circle cx="15.5" cy="11.5" r="1"/><circle cx="17.5" cy="13.8" r="1"/>',
  // カード
  card: '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18"/><path d="M6.5 15h3M12 15h1.5"/>',
  // 銀行
  bank: '<path d="m3 9 9-5 9 5"/><path d="M3.5 9h17"/><path d="M6 12v5M10 12v5M14 12v5M18 12v5"/><path d="M3.5 20h17"/>',
  // カート
  cart: '<path d="M3 4h2.5l2.2 10.5h10.3l2-7.5H6.6"/><circle cx="9.5" cy="19" r="1.3"/><circle cx="16.5" cy="19" r="1.3"/>',
  // 人＋（有料の会員登録）
  userPlus: '<circle cx="9.5" cy="8.5" r="3.5"/><path d="M3 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M18.5 5v6M15.5 8h6"/>',
  // 人（無料の会員登録）
  user: '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/>',
  // リスト（見積もり・査定）
  list: '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M8 9h.01M8 12h.01M8 15h.01M11 9h5M11 12h5M11 15h5"/>',
  // 書類（資料請求）
  doc: '<path d="M6 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 5 19V5a1.5 1.5 0 0 1 1-1.5Z"/><path d="M14 3.5V8h4"/><path d="M8.5 12h7M8.5 15.5h5"/>',
  // 共通（知らないカテゴリ）: 値札
  tag: '<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.5 1.5 0 0 1 0 2.1l-6.1 6.1a1.5 1.5 0 0 1-2.1 0Z"/><circle cx="8" cy="8" r="1.4"/>',
};

/** 上から順に調べ、最初に当てはまったアイコンを使う（「有料会員」は「会員」より先に調べる） */
const RULES: [RegExp, keyof typeof ICONS][] = [
  [/インストール/, 'download'],
  [/ゲーム|条件達成/, 'game'],
  [/カード/, 'card'],
  [/口座|銀行|証券/, 'bank'],
  [/購入|通販|ショッピング/, 'cart'],
  [/有料/, 'userPlus'],
  [/会員|登録/, 'user'],
  [/見積|査定/, 'list'],
  [/資料|請求/, 'doc'],
];

export function categoryIcon(name: string): string {
  const key = RULES.find(([re]) => re.test(name))?.[1] ?? 'tag';
  return `<svg class="category-tile__icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[key]}</svg>`;
}
