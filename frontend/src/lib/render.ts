// API のデータからカードの HTML を作る（ブラウザ側で使う）。値は必ず escapeHtml / safeUrl を通す
import type { CampaignSummary, EventSummary, NewsSummary } from './api';
import { favoriteButton } from './favorite';
import { escapeHtml, formatDate, formatDateTime, formatPoints, safeUrl } from './format';
import { mascotImg, type MascotPose } from './mascot';

/**
 * 案件カード。リンクの中にボタンを入れられないので、カードの中でリンクとお気に入りボタンを横に並べる。
 * ended（お気に入り一覧で掲載が終わった案件）は薄く出し、案件詳細へのリンクにしない。
 */
export function campaignCard(c: CampaignSummary, options: { ended?: boolean } = {}): string {
  const icon = safeUrl(c.icon_url);
  const inner = `
      ${icon ? `<img src="${escapeHtml(icon)}" alt="" loading="lazy" width="64" height="64">` : '<span class="campaign-card__noicon" aria-hidden="true"></span>'}
      <span class="campaign-card__body">
        <span class="campaign-card__name">${escapeHtml(c.name)}</span>
        ${c.category ? `<span class="campaign-card__meta">${escapeHtml(c.category)}</span>` : ''}
        ${options.ended ? '<span class="badge badge--muted campaign-card__ended">掲載終了</span>' : `<span class="campaign-card__points">${c.points != null ? `最大 ${formatPoints(c.points)}` : 'ポイントは詳細へ'}</span>`}
      </span>`;
  return `
    <div class="card campaign-card${options.ended ? ' campaign-card--ended' : ''}">
      ${options.ended ? `<span class="campaign-card__link">${inner}</span>` : `<a class="campaign-card__link" href="/campaigns/detail/?id=${c.id}">${inner}</a>`}
      ${favoriteButton(c.id, c.is_favorite)}
    </div>`;
}

export function eventCard(e: EventSummary): string {
  return `
    <a class="card event-card" href="/events/detail/?id=${e.id}">
      <span class="event-card__date">${escapeHtml(formatDateTime(e.starts_at))}${e.status === 'ended' ? '<span class="badge badge--muted">終了</span>' : ''}</span>
      <span class="event-card__title">${escapeHtml(e.title)}</span>
      ${e.summary ? `<span class="event-card__summary">${escapeHtml(e.summary)}</span>` : ''}
      <span class="event-card__meta">${escapeHtml(e.format ?? '')}${e.reward_points > 0 ? ` ・参加で ${escapeHtml(formatPoints(e.reward_points))}` : ''}${e.accepting && e.remaining_seats !== null ? ` ・${e.remaining_seats === 0 ? '満員' : `残り${e.remaining_seats}人`}` : ''}</span>
      <span class="event-card__more">詳しく見る</span>
    </a>`;
}

/**
 * 空・見つからない・読み込めないときの表示。pose を渡すとキャラクターを上に添える。
 * キャラクターは1画面1体まで（docs/brand/README.md「キャラクターの置き方」）なので、
 * すでにキャラクターがいる画面（トップ・イベント一覧など）では pose を渡さない。
 * tag は入れる先に合わせる（<ul> の中なら 'li'）。
 */
export function emptyState(text: string, pose?: MascotPose, tag: 'p' | 'li' | 'div' = 'p'): string {
  if (!pose) return `<${tag} class="empty">${escapeHtml(text)}</${tag}>`;
  const box = tag === 'p' ? 'div' : tag;
  return `<${box} class="empty empty--mascot">${mascotImg(pose)}<span class="empty__text">${escapeHtml(text)}</span></${box}>`;
}

/** 「読み込めませんでした」の共通表示（謝るポイフォックス付き） */
export const loadError = (text = '読み込めませんでした。時間をおいて再度お試しください。', tag: 'p' | 'li' | 'div' = 'p') =>
  emptyState(text, 'sorry', tag);

/** お知らせ一覧の1行（日付・タイトル・重要の印） */
export const newsRow = (n: NewsSummary) => `
  <li>
    <a class="news-row" href="/news/detail/?id=${n.id}">
      <span class="news-row__date">${n.published_at ? escapeHtml(formatDate(n.published_at)) : ''}${n.is_important ? '<span class="badge badge--important">重要</span>' : ''}</span>
      <span class="news-row__title">${escapeHtml(n.title)}</span>
    </a>
  </li>`;

// イベント一覧（S04）の行で使う線のアイコン（見本 brand/redesign/events/ に寄せた緑の線）
const lineIcon = (body: string, cls: string) =>
  `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${body}</svg>`;
const EVENT_ICON = {
  calendar: lineIcon('<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>', 'event-row__icon'),
  online: lineIcon('<rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>', 'event-row__icon'),
  place: lineIcon('<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>', 'event-row__icon'),
  // サムネイルがないイベントの絵（見本の2枚目と同じ吹き出し2つ）
  noThumb: lineIcon(
    '<path d="M3 9.5C3 7 5.2 5 8 5h1c2.8 0 5 2 5 4.5S11.8 14 9 14H7.5L4.5 16.5l.7-3.1C3.9 12.6 3 11.1 3 9.5z"/><path d="M10.5 15.6c.6 1.6 2.3 2.9 4.5 2.9h1.5l3 2.5-.7-3.1c1.3-.8 2.2-2.3 2.2-3.9 0-2.2-1.7-4-4-4.4"/><path d="M6.5 9.5h.01M8.5 9.5h.01M10.5 9.5h.01"/>',
    'event-row__nothumb',
  ),
};

/**
 * イベント一覧（S04）の1件。左にサムネイル（ないときは吹き出しの絵）、右に日時・タイトル・開催方法のチップと
 * 参加ポイント・残り枠（2026-11-29 yamada さん）・「詳しく見る」。終了したイベントは薄く出す（CSS の .event-row--ended）。
 * トップのイベントカード（eventCard）とは別の形。
 */
export function eventRow(e: EventSummary): string {
  const thumb = safeUrl(e.thumbnail_url);
  const online = (e.format ?? '').includes('オンライン');
  // 終了したイベントは参加ポイント・残り枠を出さない
  const extras = (e.status === 'ended' ? [] : [
    e.reward_points > 0 ? `参加で${formatPoints(e.reward_points)}` : '',
    e.accepting && e.remaining_seats !== null ? (e.remaining_seats === 0 ? '満員' : `残り${e.remaining_seats}人`) : '',
  ]).filter(Boolean);
  return `
    <a class="event-row${e.status === 'ended' ? ' event-row--ended' : ''}" href="/events/detail/?id=${e.id}">
      <span class="event-row__thumb">${thumb ? `<img src="${escapeHtml(thumb)}" alt="" loading="lazy" width="96" height="96">` : EVENT_ICON.noThumb}</span>
      <span class="event-row__body">
        <span class="event-row__date">${EVENT_ICON.calendar}${escapeHtml(formatDateTime(e.starts_at))}${e.status === 'ended' ? '<span class="badge badge--muted">終了</span>' : ''}</span>
        <span class="event-row__title">${escapeHtml(e.title)}</span>
        <span class="event-row__meta">
          ${e.format ? `${online ? EVENT_ICON.online : EVENT_ICON.place}<span class="event-row__chip">${escapeHtml(e.format)}</span>` : ''}
          ${extras.length ? `<span class="event-row__extra">${escapeHtml(extras.join('・'))}</span>` : ''}
        </span>
        <span class="event-row__more">詳しく見る</span>
      </span>
    </a>`;
}
