<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\Exchange\ExchangeMethod;
use App\Services\Exchange\ExchangeMethods;
use App\Services\Points\PointBalance;
use App\Services\Withdrawals\WithdrawalException;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 会員のポイント交換（換金）の申請。いまの交換先は銀行振込だけで、振込は月1回、運営が申請を確認してから行う。
 * 交換先の一覧と条件は GET /v1/exchange-methods。
 */
class WithdrawalController extends Controller
{
    /**
     * 交換申請の履歴（全交換先、新しい順に50件）。
     *
     * meta の min_points・yen_per_point・has_bank_account は銀行振込の条件（交換先を分ける前からの項目）。
     */
    public function index(Request $request, PointBalance $balance, ExchangeMethods $methods): JsonResponse
    {
        $user = $request->user();
        $bank = $methods->find(ExchangeMethod::BANK_TRANSFER);
        $items = WithdrawalRequest::where('user_id', $user->id)->latest('id')->limit(50)->get();

        return response()->json([
            'data' => $items->map(fn (WithdrawalRequest $r) => [
                'id' => $r->id,
                'exchange_method' => $r->exchange_method,
                'exchange_method_name' => $r->exchangeMethodName(),
                'points' => $r->points,
                'amount_yen' => $r->transferYen(),
                // 会員には「却下」を「取り消し（ポイント返却）」として見せる
                'status' => $r->status,
                'created_at' => $r->created_at->toIso8601String(),
                'paid_at' => $r->paid_at?->toIso8601String(),
            ]),
            'meta' => [
                'available_points' => $balance->for($user)['available'],
                'min_points' => $bank?->minPoints,
                'yen_per_point' => $bank?->yenPerPoint,
                'has_bank_account' => $user->bankAccount()->exists(),
            ],
        ]);
    }

    /**
     * 交換を申請する。
     *
     * method（交換先コード）を省略したら銀行振込（bank_transfer）。申請した時点でポイントを残高から引く。
     */
    public function store(Request $request, WithdrawalService $withdrawals, ExchangeMethods $methods): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', 'string', Rule::in($methods->visible()->keys()->all())],
        ], ['method.in' => 'この交換先は選べません。'], ['points' => 'ポイント', 'method' => '交換先']);

        try {
            $withdrawal = $withdrawals->request($request->user(), (int) $data['points'], $data['method'] ?? ExchangeMethod::BANK_TRANSFER);
        } catch (WithdrawalException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return response()->json(['data' => [
            'id' => $withdrawal->id,
            'exchange_method' => $withdrawal->exchange_method,
            'exchange_method_name' => $withdrawal->exchangeMethodName(),
            'points' => $withdrawal->points,
            'amount_yen' => $withdrawal->transferYen(),
            'status' => $withdrawal->status,
        ]], 201);
    }
}
