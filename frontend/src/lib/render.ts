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
