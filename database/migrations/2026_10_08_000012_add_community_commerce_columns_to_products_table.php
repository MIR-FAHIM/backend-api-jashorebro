<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_recommendation_enabled')->default(true)->after('is_group_buy_enabled');
            $table->decimal('recommendation_commission', 10, 2)->default(0.00)->after('is_recommendation_enabled');
            $table->boolean('is_community_shop_enabled')->default(true)->after('recommendation_commission');
            $table->decimal('supplier_allocation_price', 10, 2)->nullable()->after('is_community_shop_enabled');
            $table->decimal('min_selling_price', 10, 2)->nullable()->after('supplier_allocation_price');
            $table->decimal('max_selling_price', 10, 2)->nullable()->after('min_selling_price');
            $table->decimal('platform_fee_percent', 5, 2)->default(10.00)->after('max_selling_price');
            $table->boolean('is_group_drop_enabled')->default(true)->after('platform_fee_percent');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('supplier_allocation_price', 10, 2)->nullable()->after('price_override');
            $table->decimal('min_selling_price', 10, 2)->nullable()->after('supplier_allocation_price');
            $table->decimal('max_selling_price', 10, 2)->nullable()->after('min_selling_price');
            $table->decimal('recommendation_commission', 10, 2)->nullable()->after('max_selling_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_allocation_price',
                'min_selling_price',
                'max_selling_price',
                'recommendation_commission',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_recommendation_enabled',
                'recommendation_commission',
                'is_community_shop_enabled',
                'supplier_allocation_price',
                'min_selling_price',
                'max_selling_price',
                'platform_fee_percent',
                'is_group_drop_enabled',
            ]);
        });
    }
};
