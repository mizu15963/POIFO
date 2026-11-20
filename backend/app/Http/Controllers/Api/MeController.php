<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Services\Members\MemberException;
use App\Services\Members\MemberService;
use App\Services\Points\PointBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function show(Request $request, PointBalance $balance): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
                'points' => $balance->for($user),
                'line_friend_url' => config('poifo.line.official_friend_url'),
            ],
        ]);
    }

    public function pointHistory(Request $request): JsonResponse
    {
        $page = PointTransaction::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PointTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'type_label' => PointTransaction::TYPE_LABELS[$t->type] ?? $t->type,
                'status' => $t->status,
                'points' => $t->points,
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
                'confirmed_at' => $t->confirmed_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * 退会する。注意事項への同意（`agree`）が必要。手続き中の換金申請があるときはできない。
     * 残りのポイントは失効し、同じ LINE アカウントでは再登録できない。
     */
    public function leave(Request $request, MemberService $members): JsonResponse
    {
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => '注意事項に同意してください。']);

        try {
            $members->leave($request->user());
        } catch (MemberException $e) {
            throw ValidationException::withMessages(['leave' => $e->getMessage()]);
        }

        return $this->logout($request);
    }
}
