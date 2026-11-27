<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Admin\AuditLogger;
use App\Services\Campaigns\DummyCampaigns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 案件の表示設定（非表示・おすすめ・特集の印・並び順）。案件の中身は ASP から取り込むので変えない（仕様書 2章）。
 */
class CampaignController extends Controller
{
    public const FILTERS = [
        'listed' => 'サイトに掲載中',
        'stopped' => 'ASP 側で停止・未開始',
        'removed' => '掲載終了',
        'hidden' => '非表示',
    ];

    public function __construct(private AuditLogger $audit, private DummyCampaigns $dummies) {}

    public function index(Request $request): View
    {
        $filter = array_key_exists($request->query('filter'), self::FILTERS) ? $request->query('filter') : null;
        $keyword = trim((string) $request->query('q', ''));

        $query = Campaign::query();
        match ($filter) {
            'listed' => $query->listed(),
            'stopped' => $query->whereIn('external_status', ['not_started', 'paused']),
            'removed' => $query->where('external_status', 'removed'),
            'hidden' => $query->where('is_hidden', true),
            default => null,
        };
        if ($keyword !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($keyword, '%_\\').'%')->orWhere('external_id', $keyword));
        }

        $listedIds = Campaign::listed()->pluck('id')->flip();

        return view('admin.campaigns.index', [
            // お気に入り数はおすすめ設定の参考にする（機能仕様 4.5）
            'campaigns' => $query->with('tags')->withCount('favorites')->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id')->paginate(50)->withQueryString(),
            'listedIds' => $listedIds,
            'filter' => $filter,
            'keyword' => $keyword,
            'filters' => self::FILTERS,
            'tags' => Campaign::TAGS,
            'hasDummies' => $this->dummies->exists(),
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'is_hidden' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['required', 'integer', 'between:-9999,9999'],
            'tags' => ['array'],
            'tags.*' => ['string', Rule::in(array_keys(Campaign::TAGS))],
        ]);
        $data['is_hidden'] = $request->boolean('is_hidden');
        $data['is_featured'] = $request->boolean('is_featured');
        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $before = $campaign->only(['is_hidden', 'is_featured', 'sort_order']) + ['tags' => $campaign->tagKeys()];
        DB::transaction(function () use ($campaign, $data, $tags) {
            $campaign->update($data);
            $campaign->syncTags($tags);
        });
        $after = $data + ['tags' => $campaign->tagKeys()];
        $this->audit->log(Auth::guard('admin')->user(), 'campaign.update_display', 'campaign', $campaign->id, ['before' => $before, 'after' => $after]);

        return back()->with('status', "「{$campaign->name}」の表示設定を保存しました。");
    }

    /** 動作確認用のダミー案件を入れる（機能仕様 4.6）。ダミーがまだないときだけ入る */
    public function storeDummies(): RedirectResponse
    {
        $count = $this->dummies->insert();
        if ($count === 0) {
            return back()->withErrors(['dummy' => 'ダミー案件はすでに入っています。入れ直すときは先にすべて消してください。']);
        }

        $this->audit->log(Auth::guard('admin')->user(), 'campaign.dummy_insert', 'campaign', null, ['count' => $count]);

        return back()->with('status', "ダミー案件を{$count}件入れました。公開前に必ず消してください。");
    }

    /** ダミー案件をすべて消す。付いているお気に入りと「この案件に進む」の記録も消す */
    public function destroyDummies(): RedirectResponse
    {
        $deleted = $this->dummies->deleteAll();
        $this->audit->log(Auth::guard('admin')->user(), 'campaign.dummy_delete', 'campaign', null, $deleted);

        return back()->with('status', "ダミー案件を{$deleted['campaigns']}件消しました（お気に入り {$deleted['favorites']}件、案件への遷移記録 {$deleted['clicks']}件も削除）。");
    }
}
