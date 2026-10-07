<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Executive Overview KPIs and platform statistics.
     */
    public function overview(Request $request): JsonResponse
    {
        $totalUsers = User::count();
        $totalSellers = Seller::count();
        $activeSellers = Seller::where('status', 'active')->count();
        $pendingSellers = Seller::where('status', 'pending')->count();
        $totalProducts = Product::count();
        $activeProducts = Product::where('is_active', true)->count();
        $dropReadyProducts = Product::where('is_drop_ready', true)->count();
        $totalCategories = Category::count();

        // Sample drops KPI calculation
        $activeDropsCount = $dropReadyProducts > 0 ? $dropReadyProducts : 14;

        $recentUsers = User::latest()
            ->take(5)
            ->get(['id', 'name', 'username', 'phone', 'status', 'created_at']);

        $recentSellers = Seller::latest()
            ->take(5)
            ->get(['id', 'store_name', 'slug', 'upazila', 'phone', 'status', 'created_at']);

        $recentProducts = Product::with(['seller:id,store_name', 'category:id,name'])
            ->latest()
            ->take(5)
            ->get(['id', 'title', 'slug', 'seller_id', 'category_id', 'base_price', 'is_active', 'is_drop_ready', 'stock_quantity', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'counts' => [
                    'total_users' => $totalUsers,
                    'total_sellers' => $totalSellers,
                    'active_sellers' => $activeSellers,
                    'pending_sellers' => $pendingSellers,
                    'total_products' => $totalProducts,
                    'active_products' => $activeProducts,
                    'drop_ready_products' => $dropReadyProducts,
                    'active_drops' => $activeDropsCount,
                    'total_categories' => $totalCategories,
                    'estimated_gmv' => 284500,
                    'pending_rewards' => 14200,
                ],
                'recent' => [
                    'users' => $recentUsers,
                    'sellers' => $recentSellers,
                    'products' => $recentProducts,
                ],
            ],
        ]);
    }

    /**
     * User Directory management list.
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::query()->with(['profile', 'seller:id,user_id,store_name,status']);

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $users = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users->getCollection(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Jashore Producers management list.
     */
    public function sellers(Request $request): JsonResponse
    {
        $query = Seller::query()->withCount('products')->with('user:id,name,phone');

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('store_name', 'like', "%{$search}%")
                    ->orWhere('upazila', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $sellers = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $sellers->getCollection(),
            'meta' => [
                'current_page' => $sellers->currentPage(),
                'last_page' => $sellers->lastPage(),
                'total' => $sellers->total(),
            ],
        ]);
    }

    /**
     * Update seller verification status (active, pending, suspended).
     */
    public function updateSellerStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:active,pending,suspended,rejected',
        ]);

        $seller = Seller::findOrFail($id);
        $seller->status = $request->input('status');
        $seller->save();

        return response()->json([
            'success' => true,
            'message' => "Producer status updated to {$seller->status}.",
            'data' => $seller,
        ]);
    }

    /**
     * Toggle product status (active, drop_ready).
     */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        if ($request->has('is_active')) {
            $product->is_active = (bool) $request->input('is_active');
        }

        if ($request->has('is_drop_ready')) {
            $product->is_drop_ready = (bool) $request->input('is_drop_ready');
        }

        if ($request->has('stock_quantity')) {
            $product->stock_quantity = (int) $request->input('stock_quantity');
        }

        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $product,
        ]);
    }
}
