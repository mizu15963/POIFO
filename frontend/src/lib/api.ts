// バックエンド API（api.<domain>）をブラウザから呼ぶ。
// サイトは静的なので、会員情報・案件・イベントなど変わるデータはすべてブラウザで取得する。
// ログインは Cookie（セッション）で、別オリジンなので credentials: 'include' が必須。
import { API_BASE_URL } from './config';

export type Points = { available: number; pending: number };

export type Me = {
  display_name: string | null;
  avatar_url: string | null;
  points: Points;
  line_friend_url: string | null;
};

export type CampaignSummary = {
  id: number;
  name: string;
  category: string | null;
  platform: string | null;
  icon_url: string | null;
  points: number | null;
  is_featured: boolean;
  ends_at: string | null;
};

export type CampaignDetail = CampaignSummary & {
  description: string | null;
  conversion_condition: string | null;
  actions: { name: string | null; description: string | null; points: number | null; approve_days: number | null }[];
};

export type EventSummary = {
  id: number;
  title: string;
  summary: string | null;
  starts_at: string;
  ends_at: string | null;
  format: string | null;
  thumbnail_url: string | null;
  status: 'published' | 'ended';
  /** 参加上限人数（なければ上限なし） */
  capacity: number | null;
  /** 残りの枠（上限なしは null） */
  remaining_seats: number | null;
  /** 参加を確認した会員に付与するポイント */
  reward_points: number;
  /** 参加を受け付けているか（公開中で開始前） */
  accepting: boolean;
};

export type EventDetail = EventSummary & {
  body: string | null;
  notes: string | null;
  /** ログイン中の会員の参加状況（未参加・未ログインは null） */
  participation: 'joined' | 'cancelled' | 'attended' | 'absent' | null;
  /** 参加した会員にだけ返る */
  openchat_url: string | null;
};

export type CampaignCategory = { name: string; count: number };

export type PointHistoryItem = {
  id: number;
  type: string;
  type_label: string;
  status: 'pending' | 'confirmed' | 'rejected';
  points: number;
  description: string | null;
  created_at: string;
  confirmed_at: string | null;
};

type Paged<T> = { data: T[]; meta: { current_page: number; last_page: number } };

export type BankAccount = {
  bank_code: string;
  bank_name: string | null;
  branch_code: string;
  branch_name: string | null;
  account_type: 'ordinary' | 'checking' | 'savings';
  account_type_label: string;
  account_number_masked: string;
  account_holder_kana: string;
  holder_name: string | null;
};

export type BankAccountInput = {
  bank_code: string;
  bank_name: string;
  branch_code: string;
  branch_name: string;
  account_type: string;
  account_number: string;
  account_holder_kana: string;
  holder_name: string;
};

export type WithdrawalItem = {
  id: number;
  points: number;
  amount_yen: number;
  status: 'requested' | 'approved' | 'rejected' | 'paid';
  created_at: string;
  paid_at: string | null;
};

export type WithdrawalList = {
  data: WithdrawalItem[];
  meta: { available_points: number; min_points: number; yen_per_point: number; has_bank_account: boolean };
};

export class ApiError extends Error {
  constructor(
    public status: number,
    /** 入力エラー（422）のときの項目ごとのメッセージ */
    public errors: Record<string, string[]> = {},
  ) {
    super(`API error: ${status}`);
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    credentials: 'include',
    headers: { Accept: 'application/json', ...(init.headers || {}) },
  });
  if (!res.ok) {
    const body = res.status === 422 ? await res.json().catch(() => ({})) : {};
    throw new ApiError(res.status, body.errors ?? {});
  }
  return res.json() as Promise<T>;
}

/** POST などの前に CSRF トークンの Cookie（XSRF-TOKEN）を受け取り、ヘッダーに載せる */
async function withCsrf(init: RequestInit): Promise<RequestInit> {
  await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, { credentials: 'include' });
  const token = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
  return { ...init, headers: { ...(init.headers || {}), 'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '' } };
}

/** ログインしていなければ null */
export async function fetchMe(): Promise<Me | null> {
  try {
    return (await request<{ data: Me }>('/v1/me')).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return null;
    throw e;
  }
}

export const fetchCampaigns = (params: { featured?: boolean; perPage?: number; page?: number; category?: string } = {}) => {
  const q = new URLSearchParams();
  if (params.featured) q.set('featured', '1');
  if (params.category) q.set('category', params.category);
  if (params.perPage) q.set('per_page', String(params.perPage));
  if (params.page) q.set('page', String(params.page));
  return request<Paged<CampaignSummary>>(`/v1/campaigns?${q}`);
};

export const fetchCampaign = async (id: string) => (await request<{ data: CampaignDetail }>(`/v1/campaigns/${encodeURIComponent(id)}`)).data;

export const fetchEvents = async (params: { upcoming?: boolean; limit?: number } = {}) => {
  const q = new URLSearchParams();
  if (params.upcoming) q.set('upcoming', '1');
  if (params.limit) q.set('limit', String(params.limit));
  return (await request<{ data: EventSummary[] }>(`/v1/events?${q}`)).data;
};

export const fetchCampaignCategories = async () => (await request<{ data: CampaignCategory[] }>('/v1/campaign-categories')).data;

export const fetchEvent = async (id: string) => (await request<{ data: EventDetail }>(`/v1/events/${encodeURIComponent(id)}`)).data;

/** イベントに参加する（満員・開始後などは 422 で errors.event にメッセージ） */
export async function joinEvent(id: string): Promise<EventDetail> {
  return (await request<{ data: EventDetail }>(`/v1/events/${encodeURIComponent(id)}/participation`, await withCsrf({ method: 'POST' }))).data;
}

/** イベントの参加を取り消す（開始前だけ） */
export async function cancelEvent(id: string): Promise<EventDetail> {
  return (await request<{ data: EventDetail }>(`/v1/events/${encodeURIComponent(id)}/participation`, await withCsrf({ method: 'DELETE' }))).data;
}

export const fetchPointHistory = (page = 1) => request<Paged<PointHistoryItem>>(`/v1/me/points?page=${page}`);

export const fetchBankAccount = async () => (await request<{ data: BankAccount | null }>('/v1/me/bank-account')).data;

export async function saveBankAccount(input: BankAccountInput): Promise<BankAccount> {
  const init = await withCsrf({ method: 'PUT', body: JSON.stringify(input), headers: { 'Content-Type': 'application/json' } });
  return (await request<{ data: BankAccount }>('/v1/me/bank-account', init)).data;
}

export const fetchWithdrawals = () => request<WithdrawalList>('/v1/me/withdrawals');

export async function requestWithdrawal(points: number): Promise<void> {
  const init = await withCsrf({ method: 'POST', body: JSON.stringify({ points }), headers: { 'Content-Type': 'application/json' } });
  await request('/v1/me/withdrawals', init);
}

export async function logout(): Promise<void> {
  await request('/v1/logout', await withCsrf({ method: 'POST' }));
}

/** 退会する（手続き中の換金申請があると 422 で errors.leave にメッセージ） */
export async function leave(): Promise<void> {
  const init = await withCsrf({ method: 'POST', body: JSON.stringify({ agree: true }), headers: { 'Content-Type': 'application/json' } });
  await request('/v1/me/leave', init);
}
