// ポイント表示の共通カード（2026-11-28 yamada「ポイントを表示するところはこのデザインで統一」。見本は共有フォルダ brand/redesign/components/point-summary-card.png）。
// 左端に斜めの水色の帯と白い丸の「P」、中央にラベルと大きな数字（右上に黄色のキラキラ）、区切り線の右に判定中ポイントの囲み。
// Astro のテンプレートでは src/components/PointCard.astro、スクリプトで HTML を作るときはこちらを使う（どちらも同じ見た目。CSS は global.css の .point-card）。
import { escapeHtml } from './format';

const SPARKLE =
  '<svg class="point-card__sparkle" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 3.5 7.5 9M13 5l-2.5 5M20 10.5l-6 2" stroke="#f5c400" stroke-width="2.6" stroke-linecap="round" fill="none"/></svg>';

export interface PointCardOptions {
  label: string;
  /** 数字（単位なし、例 "16,000"）。未取得なら "—" */
  value: string;
  /** 右の判定中ポイント（単位つき、例 "0P"）。出さないなら undefined */
  side?: { label: string; value: string };
  /** カード全体をリンクにするとき */
  href?: string;
}

export function pointCardHtml(o: PointCardOptions): string {
  const tag = o.href ? 'a' : 'div';
  return `<${tag} class="point-card"${o.href ? ` href="${escapeHtml(o.href)}"` : ''}>
    <span class="point-card__badge" aria-hidden="true"><span>P</span></span>
    <span class="point-card__main">
      <span class="point-card__label">${escapeHtml(o.label)}</span>
      <span class="point-card__value"><span class="point-card__num">${escapeHtml(o.value)}</span><span class="point-card__unit">P</span>${SPARKLE}</span>
    </span>
    ${o.side ? `<span class="point-card__side"><span class="point-card__side-label">${escapeHtml(o.side.label)}</span><span class="point-card__side-value">${escapeHtml(o.side.value)}</span></span>` : ''}
  </${tag}>`;
}
