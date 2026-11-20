<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\Points\PointBalance;
use App\Services\Withdrawals\WithdrawalException;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 会員の換金申請（銀行振込）。振込は月1回、運営が申請を確認してから行う。
 */
class WithdrawalController extends Controller
{
    public function index(Request $request, PointBalance $balance): JsonResponse
    {
        $user = $request->user();
        $items = WithdrawalRequest::where('user_id', $user->id)->latest('id')->limit(50)->get();

        return response()->json([
            'data' => $items->map(fn (WithdrawalRequest $r) => [
                'id' => $r->id,
                'points' => $r->points,
                'amount_yen' => $r->transferYen(),
                // 会員には「却下」を「取り消し（ポイント返却）」として見せる
                'status' => $r->status,
                'created_at' => $r->created_at->toIso8601String(),
                'paid_at' => $r->paid_at?->toIso8601String(),
            ]),
            'meta' => [
                'available_points' => $balance->for($user)['available'],
                'min_points' => (int) config('poifo.points.min_withdrawal_points'),
                'yen_per_point' => (float) config('poifo.points.yen_per_point'),
                'has_bank_account' => $user->bankAccount()->exists(),
            ],
        ]);
    }

    public function store(Request $request, WithdrawalService $withdrawals): JsonResponse
    {
        $points = (int) $request->validate(['points' => ['required', 'integer', 'min:1']], [], ['points' => 'ポイント'])['points'];

        try {
            $withdrawal = $withdrawals->request($request->user(), $points);
        } catch (WithdrawalException $e) {
            throw ValidationException::withMessages(['points' => $e->getMessage()]);
        }

        return response()->json(['data' => [
            'id' => $withdrawal->id,
            'points' => $withdrawal->points,
            'amount_yen' => $withdrawal->transferYen(),
            'status' => $withdrawal->status,
        ]], 201);
    }
}
