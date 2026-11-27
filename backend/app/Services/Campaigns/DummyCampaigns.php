<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\Favorite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 動作確認用のダミー案件（機能仕様 4.6）。GF Rewards の取り込みが動くまで、実際の画面で一覧・詳細・お気に入りを確かめるために使う。
 *
 * 本物と同じ campaigns テーブルに provider = dummy で入れるので、API・画面はそのまま動き、消せば本物だけに戻る。
 * 成果通知は provider = gf_rewards の案件としか突き合わないので、ダミーに成果が付くことはない。
 * 公開前に必ず消す。
 */
class DummyCampaigns
{
    public const PROVIDER = 'dummy';

    /** 押しても本物の広告主に行かないリンク */
    public const CLICK_URL = 'https://example.com/';

    public function exists(): bool
    {
        return Campaign::where('provider', self::PROVIDER)->exists();
    }

    /**
     * ダミー案件を入れる。すでにあるときは何もしない（二重に押されても増えない）。
     *
     * @return int 入れた件数
     */
    public function insert(): int
    {
        return DB::transaction(function () {
            if ($this->exists()) {
                return 0;
            }

            $now = CarbonImmutable::now();
            foreach (self::definitions($now) as $i => $d) {
                $actions = array_map(fn (array $a) => [
                    'name' => $a[0],
                    'description' => $a[1],
                    'category' => $d['category'],
                    'incentive' => $d['revenue_type'] === 'fixed_rate'
                        ? ['unit_name' => 'ポイント', 'amount' => null, 'rate' => $a[2]]
                        : ['unit_name' => 'ポイント', 'amount' => $a[2], 'rate' => null],
                    'approve_conversion_limit_days' => $a[3],
                ], $d['actions']);
                $amounts = array_filter(array_column(array_column($actions, 'incentive'), 'amount'), fn ($v) => $v !== null);

                Campaign::create([
                    'provider' => self::PROVIDER,
                    'external_id' => sprintf('dummy-%02d', $i + 1),
                    'name' => '【ダミー】'.$d['name'],
                    'description' => $d['description'],
                    'conversion_condition' => $d['condition'],
                    'category' => $d['category'],
                    'platform' => $d['platform'],
                    'user_platforms' => $d['user_platforms'],
                    'icon_url' => config('poifo.front_url').'/images/mascot/'.$d['icon'].'.webp',
                    'click_url' => self::CLICK_URL,
                    'revenue_type' => $d['revenue_type'],
                    'max_points' => $amounts ? (int) floor(max($amounts)) : null,
                    'external_status' => 'started',
                    'starts_at' => $d['starts_at'],
                    'ends_at' => $d['ends_at'],
                    'conversion_actions' => $actions,
                    'synced_at' => $now,
                    'is_featured' => $d['featured'] ?? false,
                ]);
            }

            return count(self::definitions($now));
        });
    }

    /**
     * ダミー案件をすべて消す。ダミーに付いたお気に入りと「この案件に進む」の記録も一緒に消す。
     *
     * @return array{campaigns: int, favorites: int, clicks: int}
     */
    public function deleteAll(): array
    {
        return DB::transaction(function () {
            $ids = Campaign::where('provider', self::PROVIDER)->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                return ['campaigns' => 0, 'favorites' => 0, 'clicks' => 0];
            }

            return [
                'favorites' => Favorite::whereIn('campaign_id', $ids)->delete(),
                'clicks' => CampaignClick::whereIn('campaign_id', $ids)->delete(),
                'campaigns' => Campaign::whereIn('id', $ids)->delete(),
            ];
        });
    }

    /**
     * 10件分の中身。カテゴリ・端末・定額／定率・ポイント・成果地点の数・掲載期間をばらばらにする。
     * actions は [成果地点名, 説明, 定額ならポイント／定率なら率, 判定日数]。
     *
     * @return list<array<string, mixed>>
     */
    private static function definitions(CarbonImmutable $now): array
    {
        return [
            [
                'name' => 'パズルゲーム「ポイポイパズル」インストール', 'category' => 'アプリインストール', 'platform' => 'ios', 'user_platforms' => ['ios'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-get', 'featured' => true,
                'description' => 'かわいいキツネとブロックを消していくパズルゲームです（動作確認用のダミーです）。',
                'condition' => 'アプリをインストールして初回起動すること。',
                'actions' => [['インストール後の初回起動', '初めてアプリを起動したとき', 50, 7]],
                'starts_at' => $now->subDays(10), 'ends_at' => null,
            ],
            [
                'name' => 'RPG「フォックスクエスト」レベル20到達', 'category' => 'アプリ複数条件達成', 'platform' => 'android', 'user_platforms' => ['android'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-cheer', 'featured' => true,
                'description' => '冒険しながら仲間を集めるRPGです（動作確認用のダミーです）。',
                'condition' => 'インストールから30日以内に各条件を達成すること。',
                'actions' => [
                    ['チュートリアル完了', 'チュートリアルを最後まで進めたとき', 30, 3],
                    ['レベル10到達', 'プレイヤーレベルが10になったとき', 300, 14],
                    ['レベル20到達', 'プレイヤーレベルが20になったとき', 1200, 30],
                ],
                'starts_at' => $now->subDays(5), 'ends_at' => $now->addDays(40),
            ],
            [
                'name' => '動画見放題サービス 無料会員登録', 'category' => '無料会員登録', 'platform' => 'web', 'user_platforms' => ['ios', 'android', 'pc'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-wave',
                'description' => '映画やドラマが見放題の動画サービスです（動作確認用のダミーです）。',
                'condition' => '新規で無料会員登録を完了すること。過去に登録したことがある方は対象外。',
                'actions' => [['無料会員登録完了', 'メール認証まで完了したとき', 120, 30]],
                'starts_at' => $now->subDays(20), 'ends_at' => null,
            ],
            [
                'name' => 'ポイフォカード 新規発行', 'category' => 'クレジットカード（申込・発券）', 'platform' => 'web', 'user_platforms' => ['ios', 'android', 'pc'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-point', 'featured' => true,
                'description' => '年会費無料のクレジットカードです（動作確認用のダミーです）。',
                'condition' => '新規でカードを申し込み、発行されること。審査の結果によっては対象外。',
                'actions' => [['カード発行', 'カードが発行されたとき', 8000, 60]],
                'starts_at' => $now->subDays(3), 'ends_at' => $now->addDays(25),
            ],
            [
                'name' => '総合通販モール お買い物', 'category' => '商品購入（定率）', 'platform' => 'web', 'user_platforms' => ['ios', 'android', 'pc'],
                'revenue_type' => 'fixed_rate', 'icon' => 'poifox-guide',
                'description' => '日用品から家電までそろう通販モールです。購入金額に応じてポイントが付きます（動作確認用のダミーです）。',
                'condition' => '商品を購入すること。キャンセル・返品した場合は対象外。',
                'actions' => [['商品購入', '購入金額（税抜）の1%', 0.01, 45]],
                'starts_at' => $now->subDays(30), 'ends_at' => null,
            ],
            [
                'name' => 'ネット証券 口座開設', 'category' => '口座開設', 'platform' => 'web', 'user_platforms' => ['ios', 'android', 'pc'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-welcome',
                'description' => '少額から始められるネット証券です（動作確認用のダミーです）。',
                'condition' => '新規で口座開設を完了し、初回入金すること。',
                'actions' => [
                    ['口座開設', '口座開設が完了したとき', 1500, 45],
                    ['初回入金', '1万円以上を入金したとき', 3000, 60],
                ],
                'starts_at' => $now->subDays(1), 'ends_at' => $now->addDays(10),
            ],
            [
                'name' => '家計簿アプリ 有料プラン登録', 'category' => '有料会員登録（再登録不可）', 'platform' => 'ios', 'user_platforms' => ['ios'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-line',
                'description' => 'レシートを撮るだけで記録できる家計簿アプリです（動作確認用のダミーです）。',
                'condition' => 'アプリ内で有料プランに初めて登録すること。',
                'actions' => [['有料プラン登録', '有料プランの初回決済が完了したとき', 600, 30]],
                'starts_at' => $now->subDays(7), 'ends_at' => $now->addDays(3),
            ],
            [
                'name' => '保険の無料資料請求', 'category' => '資料請求', 'platform' => 'web', 'user_platforms' => ['ios', 'android', 'pc'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-guide',
                'description' => '複数の保険会社の資料をまとめて取り寄せられます（動作確認用のダミーです）。',
                'condition' => '必要事項を入力して資料請求を完了すること。',
                'actions' => [['資料請求完了', '資料請求フォームの送信が完了したとき', 250, 30]],
                'starts_at' => $now->subDays(15), 'ends_at' => null,
            ],
            [
                'name' => '車の一括査定', 'category' => '見積もり/査定', 'platform' => 'android', 'user_platforms' => ['android'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-event',
                'description' => '複数の買取店にまとめて査定を申し込めます（動作確認用のダミーです）。',
                'condition' => '査定を申し込み、買取店からの連絡に対応すること。',
                'actions' => [
                    ['査定申し込み', '査定の申し込みが完了したとき', 100, 14],
                    ['実査定完了', '買取店の実査定を受けたとき', 2000, 45],
                ],
                'starts_at' => $now->subDays(2), 'ends_at' => null,
            ],
            [
                // お気に入りの「掲載終了」を確かめるため、掲載期間が終わっている
                'name' => '掲載が終わった案件（ニュースアプリ インストール）', 'category' => 'アプリインストール', 'platform' => 'android', 'user_platforms' => ['android'],
                'revenue_type' => 'fixed_amount', 'icon' => 'poifox-sorry',
                'description' => '掲載期間が終わった案件の表示を確かめるためのダミーです。',
                'condition' => 'アプリをインストールして初回起動すること。',
                'actions' => [['インストール後の初回起動', '初めてアプリを起動したとき', 40, 7]],
                'starts_at' => $now->subDays(30), 'ends_at' => $now->subDay(),
            ],
        ];
    }
}
