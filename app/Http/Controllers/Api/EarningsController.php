<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EarningsLedger;
use App\Models\PayoutRequest;
use App\Services\EarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    public function __construct(
        protected EarningsService $earningsService
    ) {}

    /**
     * Get authenticated user's real balance summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $balances = $this->earningsService->getUserBalances($user);

        return response()->json([
            'success' => true,
            'data' => $balances,
            'balances' => $balances,
            'balance' => $balances['available'] ?? 0,
            ...$balances,
        ]);
    }

    /**
     * Get append-only financial ledger transaction history.
     */
    public function ledger(Request $request): JsonResponse
    {
        $user = $request->user();

        $ledgers = EarningsLedger::with(['order:id,order_number,status', 'orderItem:id,product_title,quantity,unit_price'])
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $ledgers->getCollection(),
            'entries' => $ledgers->getCollection(),
            'meta' => [
                'current_page' => $ledgers->currentPage(),
                'last_page' => $ledgers->lastPage(),
                'total' => $ledgers->total(),
            ],
        ]);
    }

    /**
     * Request a withdrawal payout from available earnings.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $user = $request->user();

        $input = $request->all();
        if (isset($input['payout_method'])) {
            if ($input['payout_method'] === 'bank') $input['payout_method'] = 'bank_transfer';
            if ($input['payout_method'] === 'cash') $input['payout_method'] = 'cash_hub';
        }
        if (! isset($input['account_number']) && isset($input['account_identifier'])) {
            $input['account_number'] = $input['account_identifier'];
        }
        $request->merge($input);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:50',
            'payout_method' => 'required|in:bkash,nagad,bank_transfer,cash_hub',
            'account_number' => 'required|string|max:50',
            'account_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $accountDetails = [
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'] ?? $user->name,
            'notes' => $validated['notes'] ?? null,
        ];

        $payout = $this->earningsService->requestPayout(
            user: $user,
            amount: (float) $validated['amount'],
            method: $validated['payout_method'],
            accountDetails: $accountDetails
        );

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted for administrative review.',
            'data' => $payout,
            'payout' => $payout,
        ], 201);
    }

    /**
     * List user's payout requests history.
     */
    public function payouts(Request $request): JsonResponse
    {
        $user = $request->user();

        $payouts = PayoutRequest::where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $payouts,
        ]);
    }
}
