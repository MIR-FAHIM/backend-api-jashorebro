<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    /**
     * Get community leaderboards: top sellers, top recommenders, and drop champions.
     */
    public function index(): JsonResponse
    {
        // 1. Top Community Sellers (by completed orders through their shop)
        $topSellers = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.earning_model', 'community_shop')
            ->whereNotNull('order_items.community_shop_id')
            ->whereIn('orders.status', ['processing', 'shipped', 'delivered'])
            ->where('orders.payment_status', 'paid')
            ->select(
                'order_items.beneficiary_user_id',
                'order_items.community_shop_id',
                DB::raw('count(distinct orders.id) as orders_count'),
                DB::raw('sum(order_items.quantity) as items_sold')
            )
            ->groupBy('order_items.beneficiary_user_id', 'order_items.community_shop_id')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get();

        $sellerResults = $topSellers->map(function ($row) {
            $user = User::with('communityShop')->find($row->beneficiary_user_id);
            return [
                'user_id' => $user?->id,
                'name' => $user?->name,
                'username' => $user?->username,
                'shop_name' => $user?->communityShop?->name,
                'shop_slug' => $user?->communityShop?->slug,
                'completed_orders' => (int) $row->orders_count,
                'items_sold' => (int) $row->items_sold,
                'score' => (int) $row->orders_count * 10 + (int) $row->items_sold * 2,
            ];
        });

        // 2. Top Recommenders (by completed recommendation purchases)
        $topRecommenders = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.earning_model', 'recommendation')
            ->whereNotNull('order_items.recommender_id')
            ->whereIn('orders.status', ['processing', 'shipped', 'delivered'])
            ->where('orders.payment_status', 'paid')
            ->select(
                'order_items.recommender_id',
                DB::raw('count(distinct orders.id) as referred_orders'),
                DB::raw('sum(order_items.quantity) as units_referred')
            )
            ->groupBy('order_items.recommender_id')
            ->orderByDesc('referred_orders')
            ->limit(10)
            ->get();

        $recommenderResults = $topRecommenders->map(function ($row) {
            $user = User::find($row->recommender_id);
            return [
                'user_id' => $user?->id,
                'name' => $user?->name,
                'username' => $user?->username,
                'referred_orders' => (int) $row->referred_orders,
                'units_referred' => (int) $row->units_referred,
                'score' => (int) $row->referred_orders * 15 + (int) $row->units_referred * 3,
            ];
        });

        // 3. Top Drop Organizers (by successful drops & participants)
        $topOrganizers = GroupBuyCampaign::query()
            ->whereNotNull('organizer_user_id')
            ->where('status', 'succeeded')
            ->select(
                'organizer_user_id',
                DB::raw('count(id) as successful_drops')
            )
            ->groupBy('organizer_user_id')
            ->orderByDesc('successful_drops')
            ->limit(10)
            ->get();

        $organizerResults = $topOrganizers->map(function ($row) {
            $user = User::find($row->organizer_user_id);
            return [
                'user_id' => $user?->id,
                'name' => $user?->name,
                'username' => $user?->username,
                'successful_drops' => (int) $row->successful_drops,
                'score' => (int) $row->successful_drops * 50,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'top_sellers' => $sellerResults,
                'top_recommenders' => $recommenderResults,
                'top_organizers' => $organizerResults,
            ],
        ]);
    }
}
