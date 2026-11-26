// API のデータからカードの HTML を作る（ブラウザ側で使う）。値は必ず escapeHtml / safeUrl を通す
import type { CampaignSummary, EventSummary, NewsSummary } from './api';
import { favoriteButton } from './favorite';
import { escapeHtml, formatDate, formatDateTime, formatPoints, safeUrl } from './format';

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

export const emptyState = (text: string) => `<p class="empty">${escapeHtml(text)}</p>`;

/** お知らせ一覧の1行（日付・タイトル・重要の印） */
export const newsRow = (n: NewsSummary) => `
  <li>
    <a class="news-row" href="/news/detail/?id=${n.id}">
      <span class="news-row__date">${n.published_at ? escapeHtml(formatDate(n.published_at)) : ''}${n.is_important ? '<span class="badge badge--important">重要</span>' : ''}</span>
      <span class="news-row__title">${escapeHtml(n.title)}</span>
    </a>
  </li>`;
