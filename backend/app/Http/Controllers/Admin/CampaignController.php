<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 案件の表示設定（非表示・おすすめ・並び順）。案件の中身は ASP から取り込むので変えない（仕様書 2章）。
 */
class CampaignController extends Controller
{
    public const FILTERS = [
        'listed' => 'サイトに掲載中',
        'stopped' => 'ASP 側で停止・未開始',
        'removed' => '掲載終了',
        'hidden' => '非表示',
    ];

    public function __construct(private AuditLogger $audit) {}

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
            'campaigns' => $query->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id')->paginate(50)->withQueryString(),
            'listedIds' => $listedIds,
            'filter' => $filter,
            'keyword' => $keyword,
            'filters' => self::FILTERS,
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'is_hidden' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['required', 'integer', 'between:-9999,9999'],
        ]);
        $data['is_hidden'] = $request->boolean('is_hidden');
        $data['is_featured'] = $request->boolean('is_featured');

        $before = $campaign->only(['is_hidden', 'is_featured', 'sort_order']);
        $campaign->update($data);
        $this->audit->log(Auth::guard('admin')->user(), 'campaign.update_display', 'campaign', $campaign->id, ['before' => $before, 'after' => $data]);

        return back()->with('status', "「{$campaign->name}」の表示設定を保存しました。");
    }
}
