// ポイント表示の共通カード（2026-11-28 yamada「ポイントを表示するところはこのデザインで統一」。見本は共有フォルダ brand/redesign/components/point-summary-card.png）。
// 左端に斜めの水色の帯と白い丸の「P」、中央にラベルと大きな数字、区切り線の右に判定中ポイントの囲み。
// 見本にあった数字の右上の黄色いキラキラは、桁が増えるとラベルや区切り線に重なるので外した（2026-11-29 yamada さん）。
// 数字は桁数（data-len）で文字を小さくして、100万P以上でも1行に収める（CSS は global.css の .point-card__num[data-len]）。
// Astro のテンプレートでは src/components/PointCard.astro、スクリプトで HTML を作るときはこちらを使う（どちらも同じ見た目。CSS は global.css の .point-card）。
import { escapeHtml, formatPoints, formatPointsNumber } from './format';

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
      <span class="point-card__value"><span class="point-card__num" data-len="${o.value.length}">${escapeHtml(o.value)}</span><span class="point-card__unit">P</span></span>
    </span>
    ${o.side ? `<span class="point-card__side"><span class="point-card__side-label">${escapeHtml(o.side.label)}</span><span class="point-card__side-value" data-len="${o.side.value.length}">${escapeHtml(o.side.value)}</span></span>` : ''}
  </${tag}>`;
}

/** PointCard.astro の数字を書き込む。桁数を data-len に入れて、長いときは CSS で文字を小さくする */
export function setPointCardValue(el: HTMLElement, points: number): void {
  el.textContent = formatPointsNumber(points);
  el.dataset.len = String(el.textContent.length);
}

/** PointCard.astro の判定中ポイント（単位つき）を書き込む */
export function setPointCardSide(el: HTMLElement, points: number): void {
  el.textContent = formatPoints(points);
  el.dataset.len = String(el.textContent.length);
}
