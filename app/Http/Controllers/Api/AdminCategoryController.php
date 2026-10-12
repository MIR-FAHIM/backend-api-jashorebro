<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    /**
     * List categories with parent and child hierarchy and product counts.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('q')->trim()->value();
        $hasActiveFilter = $request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== 'all';
        $isActive = $request->boolean('is_active');

        // If search is performed, return flat matching results with parent and product counts
        if ($search) {
            $categories = Category::query()
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                })
                ->when($hasActiveFilter, fn ($q) => $q->where('is_active', $isActive))
                ->with(['parent:id,name,slug', 'children'])
                ->withCount('products')
                ->orderBy('order_index')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        }

        // Tree structure: top-level root categories with their nested subcategories
        $categories = Category::query()
            ->whereNull('parent_id')
            ->when($hasActiveFilter, fn ($q) => $q->where('is_active', $isActive))
            ->withCount('products')
            ->with(['children' => function ($q) use ($hasActiveFilter, $isActive) {
                $q->when($hasActiveFilter, fn ($sq) => $sq->where('is_active', $isActive))
                    ->withCount('products')
                    ->orderBy('order_index');
            }])
            ->orderBy('order_index')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get root/parent categories for dropdown lookups.
     */
    public function parents(): JsonResponse
    {
        $parents = Category::query()
            ->whereNull('parent_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon', 'is_active']);

        return response()->json([
            'success' => true,
            'data' => $parents,
        ]);
    }

    /**
     * Get single category details.
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::with(['parent:id,name,slug', 'children'])
            ->withCount('products')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    /**
     * Create a new category (root or child).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'order_index' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
            $baseSlug = $validated['slug'];
            $count = 1;
            while (Category::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] = "{$baseSlug}-{$count}";
                $count++;
            }
        }

        $validated['order_index'] = $validated['order_index'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = Category::create($validated);
        $category->load('parent:id,name,slug');
        $category->loadCount('products');

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name}' created successfully.",
            'data' => $category,
        ], 201);
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('categories', 'slug')->ignore($id)],
            'parent_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                Rule::notIn([$id]),
            ],
            'icon' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'order_index' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Guard against circular dependency
        if (!empty($validated['parent_id'])) {
            $descendantIds = $this->getDescendantIds($category);
            if (in_array((int) $validated['parent_id'], $descendantIds, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Cannot set a descendant category as a parent.'],
                ]);
            }
        }

        $category->update($validated);
        $category->load(['parent:id,name,slug', 'children']);
        $category->loadCount('products');

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name}' updated successfully.",
            'data' => $category,
        ]);
    }

    /**
     * Safely delete a category if no subcategories or products are linked.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::withCount(['children', 'products'])->findOrFail($id);

        if ($category->children_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete '{$category->name}' because it contains {$category->children_count} subcategories. Please reassign or delete subcategories first.",
            ], 422);
        }

        if ($category->products_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete '{$category->name}' because {$category->products_count} product(s) are associated with it. Please reassign products first.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name}' deleted successfully.",
        ]);
    }

    /**
     * Recursively collect all descendant category IDs.
     */
    protected function getDescendantIds(Category $category): array
    {
        $ids = [];
        foreach ($category->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->getDescendantIds($child));
        }

        return $ids;
    }
}
