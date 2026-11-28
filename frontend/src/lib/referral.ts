// 友達紹介の紹介コードをブラウザに保存し、LINE ログインの入口に付ける（docs/specs/functional-spec.md 15.2）。
// 紹介URL（/invite/?code=）を開いてから30日のあいだに新しく登録すると、紹介元として記録される。
// どの画面の「LINEでログイン」から登録しても付くよう、BaseLayout がクリック時に入口の URL に ?ref= を足す。
import { LINE_LOGIN_URL } from './config';

const KEY = 'poifo_referral';
const DAYS = 30;
const CODE = /^[A-HJ-NP-Z2-9]{8}$/;

export const normalizeReferralCode = (code: string | null | undefined) => (code ?? '').trim().toUpperCase();
export const isReferralCodeFormat = (code: string) => CODE.test(code);

export function saveReferralCode(code: string): void {
  const c = normalizeReferralCode(code);
  if (!isReferralCodeFormat(c)) return;
  try {
    localStorage.setItem(KEY, JSON.stringify({ code: c, expires: Date.now() + DAYS * 86400_000 }));
  } catch {
    // 保存できない（プライベートモードなど）ときは紹介なしで登録になる
  }
}

export function loadReferralCode(): string | null {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? 'null') as { code?: string; expires?: number } | null;
    if (!saved?.code || !saved.expires || saved.expires < Date.now()) {
      localStorage.removeItem(KEY);
      return null;
    }
    return isReferralCodeFormat(saved.code) ? saved.code : null;
  } catch {
    return null;
  }
}

export function clearReferralCode(): void {
  try {
    localStorage.removeItem(KEY);
  } catch {
    // 何もしない
  }
}

/** LINE ログインの入口に、保存した紹介コードを付ける（コードがなければそのまま） */
export function withReferral(url: string): string {
  const code = loadReferralCode();
  if (!code || !url.startsWith(LINE_LOGIN_URL)) return url;
  const u = new URL(url);
  u.searchParams.set('ref', code);
  return u.toString();
}
