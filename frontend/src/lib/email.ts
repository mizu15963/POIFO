// メールアドレス未登録の会員への案内（機能仕様 3.5、画面仕様 S01・S03・S27）
import type { Me } from './api';

/**
 * ?back= / ?next= に入れてよいのはこのサイトの中のページだけ（外部サイトへ飛ばされるのを防ぐ）。
 * 文字列の見た目で判定すると、タブや改行（ブラウザが URL から取り除く）を挟んだ "/\t/evil.example/" などをすり抜けるので、
 * ブラウザと同じく URL として解釈してから origin を比べ、同じサイトのパス部分だけを返す（メンテナンス画面の ?back= と同じ考え方）。
 */
export function safeBackPath(value: string | null | undefined, origin = location.origin): string | null {
  if (!value) return null;
  try {
    const url = new URL(value, origin);
    if (url.origin !== origin) return null;
    return url.pathname + url.search + url.hash;
  } catch {
    return null;
  }
}

/** 初回登録（S27）の URL。登録後は back のページへ戻す */
export function welcomeUrl(back = location.pathname + location.search): string {
  return `/welcome/?back=${encodeURIComponent(back)}`;
}

/** メールアドレスを入れていない会員か（確認待ちは入れた扱い。案件には進める） */
export const needsEmail = (me: Me | null): me is Me => !!me && !me.email_registered;
