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
};

export type EventDetail = EventSummary & { body: string | null; notes: string | null; openchat_url: string | null };

export type PointHistoryItem = {
  id: number;
  type: string;
  status: 'pending' | 'confirmed' | 'rejected';
  points: number;
  description: string | null;
  created_at: string;
  confirmed_at: string | null;
};

type Paged<T> = { data: T[]; meta: { current_page: number; last_page: number } };

export class ApiError extends Error {
  constructor(public status: number) {
    super(`API error: ${status}`);
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    credentials: 'include',
    headers: { Accept: 'application/json', ...(init.headers || {}) },
  });
  if (!res.ok) throw new ApiError(res.status);
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

export const fetchCampaigns = (params: { featured?: boolean; perPage?: number; page?: number } = {}) => {
  const q = new URLSearchParams();
  if (params.featured) q.set('featured', '1');
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

export const fetchEvent = async (id: string) => (await request<{ data: EventDetail }>(`/v1/events/${encodeURIComponent(id)}`)).data;

export const fetchPointHistory = (page = 1) => request<Paged<PointHistoryItem>>(`/v1/me/points?page=${page}`);

export async function logout(): Promise<void> {
  await request('/v1/logout', await withCsrf({ method: 'POST' }));
}
