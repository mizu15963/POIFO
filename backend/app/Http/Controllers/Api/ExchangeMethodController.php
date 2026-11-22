<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Exchange\ExchangeMethod;
use App\Services\Exchange\ExchangeMethods;
use Illuminate\Http\JsonResponse;

/**
 * ポイントの交換先（docs/specs/functional-spec.md 7章）。
 */
class ExchangeMethodController extends Controller
{
    /**
     * 交換先の一覧と条件。
     *
     * 表示順に並べる。受付停止の交換先も一覧には出す（accepting が false）。非表示の交換先は返さない。
     */
    public function index(ExchangeMethods $methods): JsonResponse
    {
        return response()->json([
            'data' => $methods->visible()->values()->map(fn (ExchangeMethod $m) => $m->toArray()),
        ]);
    }
}
