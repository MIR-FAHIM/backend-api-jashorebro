<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SellerController extends Controller
{
    /**
     * List active verified sellers in Jashore.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Seller::query()
            ->where('status', 'active')
            ->withCount('products');

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('store_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('upazila', 'like', "%{$search}%");
            });
        }

        $sellers = $query->latest('rating_avg')->paginate(20);

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
     * Get seller profile and their active products.
     */
    public function show(string $slug): JsonResponse
    {
        $seller = Seller::where('slug', $slug)
            ->where('status', 'active')
            ->withCount('products')
            ->first();

        if (! $seller) {
            return response()->json(['success' => false, 'message' => 'Seller not found.'], 404);
        }

        $products = Product::where('seller_id', $seller->id)
            ->where('is_active', true)
            ->with(['category', 'primaryImage'])
            ->latest()
            ->paginate(12);

        return response()->json([
            'success' => true,
            'data' => [
                'seller' => $seller,
                'products' => $products->getCollection()->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'slug' => $p->slug,
                    'base_price' => $p->base_price,
                    'compare_price' => $p->compare_price,
                    'image' => $p->primaryImage?->image_url,
                    'rating_avg' => $p->rating_avg,
                    'category' => $p->category->name,
                ]),
                'products_meta' => [
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Authenticated user application to register as a merchant.
     */
    public function apply(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->seller()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an existing seller account or pending application.',
            ], 400);
        }

        $validated = $request->validate([
            'store_name' => ['required', 'string', 'max:120', 'unique:sellers,store_name'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'upazila' => ['required', 'string', 'max:50'],
            'district' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:300'],
            'tagline' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trade_license_number' => ['nullable', 'string', 'max:50'],
        ]);

        $slug = Str::slug($validated['store_name']);
        // Ensure uniqueness
        if (Seller::where('slug', $slug)->exists()) {
            $slug = $slug.'-'.Str::random(4);
        }

        $seller = Seller::create([
            'user_id' => $user->id,
            'store_name' => $validated['store_name'],
            'slug' => $slug,
            'tagline' => $validated['tagline'] ?? null,
            'description' => $validated['description'] ?? null,
            'contact_phone' => $validated['contact_phone'],
            'contact_email' => $validated['contact_email'] ?? $user->email,
            'district' => $validated['district'] ?? 'Jashore',
            'upazila' => $validated['upazila'],
            'address' => $validated['address'],
            'trade_license_number' => $validated['trade_license_number'] ?? null,
            'status' => 'pending', // Awaiting admin review
        ]);

        // Add user as owner member
        $seller->members()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'permissions' => ['all'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Seller application submitted successfully! Our community team in Jashore will review it shortly.',
            'data' => $seller,
        ], 201);
    }
}
