// ページ内のスクリプト同士でログイン中の会員情報を共有する（API を何度も呼ばないため）
import type { Me } from './api';

let resolveMe: (me: Me | null) => void;
const mePromise = new Promise<Me | null>((resolve) => (resolveMe = resolve));

export const setMe = (me: Me | null) => resolveMe(me);

/** BaseLayout が /v1/me を取得し終わったら解決する */
export const whenMe = () => mePromise;
