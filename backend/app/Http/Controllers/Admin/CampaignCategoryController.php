<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignCategorySetting;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A18 カテゴリの表示（機能仕様 4.7）。メニューの「カテゴリから探す」のボタンの表示名・並び・出すかどうか。
 * カテゴリそのものは ASP から取り込んだ値のまま変えない。
 */
class CampaignCategoryController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.campaign-categories.index', ['rows' => $this->rows()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'categories' => ['required', 'array', 'max:500'],
            'categories.*.category' => ['required', 'string', 'max:64'],
            'categories.*.label' => ['nullable', 'string', 'max:32'],
            'categories.*.sort_order' => ['nullable', 'integer', 'between:-9999,9999'],
            'categories.*.in_menu' => ['nullable', 'boolean'],
        ], [], [
            'categories.*.label' => '表示名',
            'categories.*.sort_order' => '並び順',
        ]);

        $changes = [];
        DB::transaction(function () use ($data, &$changes) {
            foreach ($data['categories'] as $row) {
                $values = [
                    'label' => trim((string) ($row['label'] ?? '')) ?: null,
                    'sort_order' => isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : null,
                    'in_menu' => (bool) ($row['in_menu'] ?? false),
                ];
                $setting = CampaignCategorySetting::firstOrNew(['category' => $row['category']]);
                $before = $setting->exists ? $setting->only(['label', 'sort_order', 'in_menu']) : ['label' => null, 'sort_order' => null, 'in_menu' => true];
                if ($before == $values) {
                    continue;
                }
                $setting->fill($values)->save();
                $changes[] = ['category' => $row['category'], 'before' => $before, 'after' => $values];
            }
        });

        if ($changes) {
            $this->audit->log(Auth::guard('admin')->user(), 'campaign_category.update', payload: ['changes' => $changes]);
        }

        return redirect()->route('admin.campaign-categories.index')->with('status', $changes ? 'カテゴリの表示を保存しました。' : '変更はありませんでした。');
    }

    /**
     * 画面に出す行。取り込んだ案件にあるカテゴリ（掲載中でないものも）と、設定だけあるカテゴリをまとめる。
     * 並びは会員サイトと同じ（並び順 → 掲載中の件数の多い順 → 名前）。
     *
     * @return Collection<int, array{category: string, listed: int, total: int, setting: ?CampaignCategorySetting}>
     */
    private function rows(): Collection
    {
        $total = Campaign::whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) as count')->groupBy('category')->pluck('count', 'category');
        $listed = Campaign::listed()->whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) as count')->groupBy('category')->pluck('count', 'category');
        $settings = CampaignCategorySetting::all()->keyBy('category');

        return $total->keys()->merge($settings->keys())->unique()->map(fn ($c) => [
            'category' => (string) $c,
            'listed' => (int) ($listed[$c] ?? 0),
            'total' => (int) ($total[$c] ?? 0),
            'setting' => $settings->get($c),
        ])->sort(fn ($a, $b) => [$a['setting']?->sort_order === null, $a['setting']?->sort_order, -$a['listed'], $a['category']]
            <=> [$b['setting']?->sort_order === null, $b['setting']?->sort_order, -$b['listed'], $b['category']])->values();
    }
}
