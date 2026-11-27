// トップの案件メニュー（docs/specs/screen-spec.md S01・機能仕様 4.2）。
// 絵は納品画像 section-category-menu（5行が1枚に入っている）なので、トップでは画像の上に透明なリンクを重ねる。
// 案件一覧（S02）はこのクエリで開き、見出しをメニューの名前にする。
export interface CampaignMenuItem {
  label: string;
  /** 画像の中の説明（読み上げ用にも使う） */
  description: string;
  /** 案件一覧の URL（/campaigns/ のクエリ） */
  query: { featured?: true; tag?: 'beginner' | 'game' | 'together'; sort?: 'points' };
}

export const CAMPAIGN_MENU: CampaignMenuItem[] = [
  { label: 'おすすめ案件', description: '今、注目の案件をピックアップ！', query: { featured: true } },
  { label: 'はじめて向け案件', description: '初めての方でも取り組みやすい！', query: { tag: 'beginner' } },
  { label: '高還元案件', description: 'ポイントをたくさん貯めたい方に！', query: { sort: 'points' } },
  { label: 'ゲーム案件特集', description: 'ゲームを楽しみながらポイントGET！', query: { tag: 'game' } },
  { label: 'みんなで挑戦中の案件', description: '他のユーザーと一緒にチャレンジ！', query: { tag: 'together' } },
];

export function campaignMenuHref(item: CampaignMenuItem): string {
  const q = new URLSearchParams();
  if (item.query.featured) q.set('featured', '1');
  if (item.query.tag) q.set('tag', item.query.tag);
  if (item.query.sort) q.set('sort', item.query.sort);
  return `/campaigns/?${q}`;
}

/** 案件一覧の URL のクエリから、どのメニューで開いたかを探す（なければ null = すべて） */
export function campaignMenuFromSearch(search: URLSearchParams): CampaignMenuItem | null {
  return (
    CAMPAIGN_MENU.find(
      (m) =>
        (m.query.featured ? search.get('featured') === '1' : true) &&
        (m.query.tag ? search.get('tag') === m.query.tag : true) &&
        (m.query.sort ? search.get('sort') === m.query.sort : true),
    ) ?? null
  );
}
