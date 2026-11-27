const dateTime = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo',
  month: 'numeric',
  day: 'numeric',
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
});
const date = new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', year: 'numeric', month: 'numeric', day: 'numeric' });

export const formatDateTime = (iso: string) => dateTime.format(new Date(iso));
export const formatDate = (iso: string) => date.format(new Date(iso));
// ポイントの単位はサイト全体で「P」（2026-11-28 yamada）
export const formatPoints = (n: number) => `${n.toLocaleString('ja-JP')}P`;
/** 単位なしの数字（ポイントカードで単位を小さく別に出すとき） */
export const formatPointsNumber = (n: number) => n.toLocaleString('ja-JP');

/** API から来た文字列を HTML に入れるときは必ず通す */
export const escapeHtml = (s: string | null | undefined) =>
  (s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

/** URL を属性に入れる前に http(s) だけ許可する */
export const safeUrl = (u: string | null | undefined) => (u && /^https?:\/\//.test(u) ? u : '');

/**
 * プレーンテキストを HTML にする。http(s) の URL だけをリンクにし、それ以外と URL 自体もエスケープする（お知らせの本文用）。
 * 改行は .prose（white-space: pre-wrap）でそのまま見せる
 */
export function linkify(text: string): string {
  let html = '';
  let last = 0;
  for (const m of text.matchAll(/https?:\/\/[^\s<>"'）」、。]+/g)) {
    // 文末の句読点・閉じ括弧は URL に含めない
    const url = m[0].replace(/[.,!?)]+$/, '');
    html += escapeHtml(text.slice(last, m.index)) + `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>`;
    last = m.index! + url.length;
  }
  return html + escapeHtml(text.slice(last));
}
