<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * List all active categories with product counts.
     */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => function ($query) {
                $query->where('is_active', true)->withCount('products');
            }])
            ->withCount('products')
            ->orderBy('order_index')
            ->get()
            ->map(function (Category $cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'icon' => $cat->icon,
                    'image_url' => $cat->image_url,
                    'description' => $cat->description,
                    'products_count' => $cat->products_count,
                    'children' => $cat->children->map(fn ($sub) => [
                        'id' => $sub->id,
                        'name' => $sub->name,
                        'slug' => $sub->slug,
                        'icon' => $sub->icon,
                        'products_count' => $sub->products_count,
                    ]),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Single category details by slug.
     */
    public function show(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->withCount('products')
            ->first();

        if (! $category) {
            return response()->json(['success' => false, 'message' => 'Category not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }
}
