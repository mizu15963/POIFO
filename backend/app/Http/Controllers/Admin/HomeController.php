<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $counts = WithdrawalRequest::query()
            ->selectRaw('status, COUNT(*) as count')
            ->whereIn('status', [WithdrawalRequest::STATUS_REQUESTED, WithdrawalRequest::STATUS_APPROVED])
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('admin.home', [
            'requestedCount' => (int) ($counts[WithdrawalRequest::STATUS_REQUESTED] ?? 0),
            'approvedCount' => (int) ($counts[WithdrawalRequest::STATUS_APPROVED] ?? 0),
        ]);
    }
}
