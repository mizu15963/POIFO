<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * お知らせの登録・編集（docs/specs/functional-spec.md 11.1、画面は A14・A15）。
 */
class NewsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), News::STATUS_LABELS) ? $request->query('status') : null;

        return view('admin.news.index', [
            'news' => News::query()->when($status, fn ($q) => $q->where('status', $status))
                ->orderByRaw('published_at IS NULL DESC')->orderByDesc('published_at')->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('admin.news.form', ['news' => new News(['status' => News::STATUS_DRAFT, 'is_important' => false])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        $news = News::create($this->validated($request) + ['created_by' => $admin?->id]);
        $this->audit->log($admin, 'news.create', 'news', $news->id, ['status' => $news->status]);

        return redirect()->route('admin.news.index')->with('status', 'お知らせを登録しました。');
    }

    public function edit(News $news): View
    {
        return view('admin.news.form', ['news' => $news]);
    }

    public function update(Request $request, News $news): RedirectResponse
    {
        $news->update($this->validated($request));
        $this->audit->log(Auth::guard('admin')->user(), 'news.update', 'news', $news->id, ['changed' => array_keys($news->getChanges())]);

        return redirect()->route('admin.news.index')->with('status', 'お知らせを保存しました。');
    }

    public function destroy(News $news): RedirectResponse
    {
        // 公開したものは会員が見た可能性があるので消さず、公開終了日時を入れて下げる
        if ($news->status !== News::STATUS_DRAFT) {
            return back()->withErrors(['news' => '削除できるのは下書きだけです。公開したお知らせは、公開終了日時を入れて下げてください。']);
        }

        $this->audit->log(Auth::guard('admin')->user(), 'news.delete', 'news', $news->id, ['title' => $news->title]);
        $news->delete();

        return redirect()->route('admin.news.index')->with('status', 'お知らせを削除しました。');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:5000'],
            'is_important' => ['boolean'],
            'status' => ['required', Rule::in(array_keys(News::STATUS_LABELS))],
            'published_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ], [], [
            'title' => 'タイトル', 'body' => '本文', 'status' => '状態', 'published_at' => '公開日時', 'expires_at' => '公開終了日時',
        ]);
        $data['is_important'] = $request->boolean('is_important');

        return $data;
    }
}
