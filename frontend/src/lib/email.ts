// メールアドレス未登録の会員への案内（機能仕様 3.5、画面仕様 S01・S03・S27）
import type { Me } from './api';

/** ?back= に入れてよいのはサイト内のパスだけ（//evil.example のような外部への移動を防ぐ） */
export function safeBackPath(value: string | null | undefined): string | null {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return null;
  return value;
}

/** 初回登録（S27）の URL。登録後は back のページへ戻す */
export function welcomeUrl(back = location.pathname + location.search): string {
  return `/welcome/?back=${encodeURIComponent(back)}`;
}

/** メールアドレスを入れていない会員か（確認待ちは入れた扱い。案件には進める） */
export const needsEmail = (me: Me | null): me is Me => !!me && !me.email_registered;
