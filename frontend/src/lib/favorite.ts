// お気に入りボタン（案件カード・案件詳細・お気に入り一覧で共通。docs/specs/functional-spec.md 4.5）。
// ボタンはスクリプトで差し込むので、押したときの処理は親要素への委譲でまとめて付ける
import { ApiError, setFavorite } from './api';
import { whenMe } from './session';

const STAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>';

/** お気に入りボタンの HTML。登録済みは塗りつぶし（aria-pressed=true） */
export function favoriteButton(campaignId: number, on: boolean): string {
  return `<button type="button" class="fav-btn" data-favorite="${campaignId}" aria-pressed="${on}" aria-label="${on ? 'お気に入りから外す' : 'お気に入りに追加'}">${STAR}</button>`;
}

let toastTimer: number | undefined;

/** 画面下に短いお知らせを出す（alert はブラウザを止めるので使わない） */
export function toast(message: string) {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('p');
    el.id = 'toast';
    el.className = 'toast';
    el.setAttribute('role', 'status');
    document.body.append(el);
  }
  el.textContent = message;
  el.hidden = false;
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => (el!.hidden = true), 3000);
}

type Options = {
  /** 登録・解除が終わったあとに呼ぶ（お気に入り一覧で、外した案件を消すときなどに使う） */
  onChange?: (campaignId: number, on: boolean, button: HTMLButtonElement) => void;
};

/** root の中のお気に入りボタンを押せるようにする。未ログインならログイン画面へ */
export function bindFavoriteButtons(root: HTMLElement, options: Options = {}) {
  root.addEventListener('click', async (ev) => {
    const button = (ev.target as HTMLElement).closest<HTMLButtonElement>('button[data-favorite]');
    if (!button || button.disabled) return;
    ev.preventDefault();
    const me = await whenMe();
    if (!me) {
      location.href = '/login/';
      return;
    }
    const id = Number(button.dataset.favorite);
    const on = button.getAttribute('aria-pressed') !== 'true';
    button.disabled = true;
    try {
      await setFavorite(id, on);
      // 同じ案件のボタンが画面に複数あってもそろえる
      document.querySelectorAll<HTMLButtonElement>(`button[data-favorite="${id}"]`).forEach((b) => {
        b.setAttribute('aria-pressed', String(on));
        b.setAttribute('aria-label', on ? 'お気に入りから外す' : 'お気に入りに追加');
      });
      toast(on ? 'お気に入りに追加しました。' : 'お気に入りから外しました。');
      options.onChange?.(id, on, button);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        location.href = '/login/';
        return;
      }
      const message = e instanceof ApiError && e.status === 422 ? Object.values(e.errors).flat()[0] : undefined;
      toast(message ?? 'お気に入りを変更できませんでした。時間をおいてもう一度お試しください。');
    } finally {
      button.disabled = false;
    }
  });
}
