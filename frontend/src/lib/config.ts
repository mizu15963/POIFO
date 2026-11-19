// ビルド時の公開設定。値は .env（PUBLIC_*）から入る
export const API_BASE_URL = (import.meta.env.PUBLIC_API_BASE_URL || 'http://api.localhost:8000').replace(/\/$/, '');
export const LINE_OFFICIAL_URL = import.meta.env.PUBLIC_LINE_OFFICIAL_URL || '';

/** LINE ログイン（会員登録も兼ねる）の入口。API 側でセッションを作ってからサイトに戻ってくる */
export const LINE_LOGIN_URL = `${API_BASE_URL}/auth/line/redirect`;

/** 案件への遷移。API が計測リンクに会員IDを付けてリダイレクトする */
export const campaignGoUrl = (id: number) => `${API_BASE_URL}/go/${id}`;
