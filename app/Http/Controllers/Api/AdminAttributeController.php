<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAttributeController extends Controller
{
    /**
     * List all attributes with their child items.
     */
    public function index(): JsonResponse
    {
        $attributes = Attribute::with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attributes,
        ]);
    }

    /**
     * Create a new attribute definition.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:attributes,slug',
            'type' => 'required|in:button,color,select,radio',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
            'is_variant' => 'boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $attribute = Attribute::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute created successfully.',
            'data' => $attribute->load('items'),
        ], 201);
    }

    /**
     * Update an attribute.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $attribute = Attribute::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'slug' => "sometimes|nullable|string|max:100|unique:attributes,slug,{$id}",
            'type' => 'sometimes|required|in:button,color,select,radio',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
            'is_variant' => 'boolean',
        ]);

        $attribute->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute updated successfully.',
            'data' => $attribute->load('items'),
        ]);
    }

    /**
     * Delete an attribute.
     */
    public function destroy(int $id): JsonResponse
    {
        $attribute = Attribute::findOrFail($id);
        $attribute->delete();

        return response()->json([
            'success' => true,
            'message' => 'Attribute deleted successfully.',
        ]);
    }

    /**
     * Add a selectable item/option to an attribute.
     */
    public function storeItem(Request $request, int $attributeId): JsonResponse
    {
        $attribute = Attribute::findOrFail($attributeId);

        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'value' => 'required|string|max:100',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'color_code' => 'nullable|string|max:30',
            'image_url' => 'nullable|string',
        ]);

        // Check uniqueness of value within attribute
        if ($attribute->items()->where('value', $validated['value'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An item with this value already exists for this attribute.',
            ], 422);
        }

        $item = $attribute->items()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute item added.',
            'data' => $item,
        ], 201);
    }

    /**
     * Update an attribute item.
     */
    public function updateItem(Request $request, int $attributeId, int $itemId): JsonResponse
    {
        $item = AttributeItem::where('attribute_id', $attributeId)->findOrFail($itemId);

        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:100',
            'value' => 'sometimes|required|string|max:100',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'color_code' => 'nullable|string|max:30',
            'image_url' => 'nullable|string',
        ]);

        if (isset($validated['value']) && $validated['value'] !== $item->value) {
            if (AttributeItem::where('attribute_id', $attributeId)->where('value', $validated['value'])->where('id', '!=', $itemId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'An item with this value already exists for this attribute.',
                ], 422);
            }
        }

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute item updated.',
            'data' => $item,
        ]);
    }

    /**
     * Delete an attribute item.
     */
    public function destroyItem(int $attributeId, int $itemId): JsonResponse
    {
        $item = AttributeItem::where('attribute_id', $attributeId)->findOrFail($itemId);
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Attribute item removed.',
        ]);
    }
}
