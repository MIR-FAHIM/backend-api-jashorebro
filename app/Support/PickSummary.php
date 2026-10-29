<?php

namespace App\Support;

use App\Models\UserPick;

class PickSummary
{
    public static function relations(): array
    {
        return [
            'product' => fn ($query) => $query->with(ProductSummary::relations())->withCount('variants'),
        ];
    }

    public static function make(UserPick $pick): array
    {
        $data = [
            'id' => $pick->id,
            'user_id' => $pick->user_id,
            'product_id' => $pick->product_id,
            'caption' => $pick->caption,
            'personal_caption' => $pick->caption,
            'display_order' => $pick->display_order,
            'is_featured' => $pick->is_featured,
            'is_public' => $pick->is_public,
            'recommendation_code' => $pick->recommendation_code,
            'created_at' => $pick->created_at?->toIso8601String(),
            'updated_at' => $pick->updated_at?->toIso8601String(),
            'product' => $pick->product ? ProductSummary::make($pick->product) : null,
        ];

        if ($pick->relationLoaded('user')) {
            $data['user'] = $pick->user ? [
                'id' => $pick->user->id,
                'name' => $pick->user->name,
                'username' => $pick->user->username,
            ] : null;
        }

        return $data;
    }
}
