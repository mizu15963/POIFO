<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignClick;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Members\MemberException;
use App\Services\Members\MemberService;
use App\Services\Points\PointBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 会員の検索・詳細・停止／再開・ポイントの手動調整（仕様書 4章）。
 */
class MemberController extends Controller
{
    public function __construct(private MemberService $members, private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));
        $status = array_key_exists($request->query('status'), User::STATUS_LABELS) ? $request->query('status') : null;

        $users = User::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($keyword !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('uid', strtolower($keyword))
                ->orWhere('display_name', 'like', '%'.addcslashes($keyword, '%_\\').'%')))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.members.index', ['users' => $users, 'keyword' => $keyword, 'status' => $status]);
    }

    public function show(User $member, PointBalance $balance): View
    {
        return view('admin.members.show', [
            'member' => $member,
            'balance' => $balance->for($member),
            'transactions' => $member->pointTransactions()->latest('id')->limit(50)->get(),
            'withdrawals' => $member->withdrawalRequests()->latest('id')->limit(20)->get(),
            'conversions' => $member->conversions()->latest('id')->limit(30)->get(),
            'participations' => $member->eventParticipations()->with('event')->latest('id')->limit(20)->get(),
            'clicks' => CampaignClick::query()->with('campaign')->where('user_id', $member->id)->latest('id')->limit(20)->get(),
            'bank' => $member->bankAccount,
        ]);
    }

    public function suspend(Request $request, User $member): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => '停止の理由']);

        return $this->run(fn () => $this->members->suspend($member, $data['reason']), 'member.suspend', $member, ['reason' => $data['reason']], '会員を停止しました。');
    }

    public function resume(User $member): RedirectResponse
    {
        return $this->run(fn () => $this->members->resume($member), 'member.resume', $member, [], '会員を再開しました。');
    }

    public function adjustPoints(Request $request, User $member): RedirectResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'between:-'.MemberService::MAX_ADJUSTMENT.','.MemberService::MAX_ADJUSTMENT],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['points' => 'ポイント', 'reason' => '理由']);

        $admin = Auth::guard('admin')->user();

        return $this->run(
            fn () => $this->members->adjustPoints($member, $admin, (int) $data['points'], $data['reason']),
            'points.adjust', $member, $data, number_format($data['points']).' ポイントを調整しました。',
        );
    }

    private function run(callable $action, string $log, User $member, array $payload, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (MemberException $e) {
            return back()->withErrors(['member' => $e->getMessage()])->withInput();
        }

        $this->audit->log(Auth::guard('admin')->user(), $log, 'user', $member->id, $payload);

        return back()->with('status', $message);
    }
}
