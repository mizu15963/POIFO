// 交換先の条件を画面に出すときの言い回し（交換先一覧 S12・詳細 S20 で共通）
import type { ExchangeMethod } from './api';

export function exchangeFeeText(m: ExchangeMethod): string {
  if (m.fee.type === 'fixed' && m.fee.amount_yen > 0) return `${m.fee.amount_yen.toLocaleString('ja-JP')}円`;
  if (m.fee.type === 'percent' && m.fee.percent > 0) return `${m.fee.percent}%`;
  return '無料';
}

/** 交換額（円未満切り捨て）と手数料を引いた受取額。バックエンドの ExchangeMethod と同じ計算 */
export function exchangeYen(m: ExchangeMethod, points: number): { amount: number; fee: number; receive: number } {
  const amount = Math.floor(points * m.yen_per_point);
  let fee = 0;
  if (m.fee.type === 'fixed') fee = m.fee.amount_yen;
  if (m.fee.type === 'percent') fee = Math.floor((amount * m.fee.percent) / 100);
  fee = Math.min(Math.max(0, fee), amount);
  return { amount, fee, receive: amount - fee };
}
