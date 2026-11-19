// API のデータからカードの HTML を作る（ブラウザ側で使う）。値は必ず escapeHtml / safeUrl を通す
import type { CampaignSummary, EventSummary } from './api';
import { escapeHtml, formatDateTime, formatPoints, safeUrl } from './format';

export function campaignCard(c: CampaignSummary): string {
  const icon = safeUrl(c.icon_url);
  return `
    <a class="card campaign-card" href="/campaigns/detail/?id=${c.id}">
      ${icon ? `<img src="${escapeHtml(icon)}" alt="" loading="lazy" width="64" height="64">` : '<span class="campaign-card__noicon" aria-hidden="true"></span>'}
      <span class="campaign-card__body">
        <span class="campaign-card__name">${escapeHtml(c.name)}</span>
        ${c.category ? `<span class="campaign-card__meta">${escapeHtml(c.category)}</span>` : ''}
        <span class="campaign-card__points">${c.points != null ? `最大 ${formatPoints(c.points)}` : 'ポイントは詳細へ'}</span>
      </span>
    </a>`;
}

export function eventCard(e: EventSummary): string {
  return `
    <a class="card event-card" href="/events/detail/?id=${e.id}">
      <span class="event-card__date">${escapeHtml(formatDateTime(e.starts_at))}${e.status === 'ended' ? '<span class="badge badge--muted">終了</span>' : ''}</span>
      <span class="event-card__title">${escapeHtml(e.title)}</span>
      ${e.summary ? `<span class="event-card__summary">${escapeHtml(e.summary)}</span>` : ''}
      <span class="event-card__meta">${escapeHtml(e.format ?? '')}</span>
      <span class="event-card__more">詳しく見る</span>
    </a>`;
}

export const emptyState = (text: string) => `<p class="empty">${escapeHtml(text)}</p>`;
