<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralAttributionController extends Controller
{
    public function __construct(protected ReferralAttributionService $attributionService) {}

    public function resolve(Request $request): JsonResponse
    {
        $input = $request->validate([
            'product_id' => 'required|integer|min:1',
            'recommendation_code' => 'required|string|max:255',
            'visitor_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'attribution_token' => ['nullable', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $result = $this->attributionService->resolveVisit($input, $request->user('sanctum'));

        return response()->json(['success' => true, 'data' => $this->attributionService->publicData($result)]);
    }

    public function lock(Request $request): JsonResponse
    {
        $input = $request->validate([
            'product_id' => 'required|integer|min:1',
            'visitor_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'attribution_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $result = $this->attributionService->lockVisit($input, $request->user('sanctum'));

        return response()->json(['success' => true, 'data' => $this->attributionService->publicData($result)]);
    }
}
