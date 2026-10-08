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
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('earning_model')->default('none')->after('purchase_mode'); // none, community_shop, recommendation, group_drop
            $table->foreignId('community_shop_id')->nullable()->constrained('community_shops')->nullOnDelete()->after('earning_model');
            $table->foreignId('shop_listing_id')->nullable()->constrained('shop_listings')->nullOnDelete()->after('community_shop_id');
            $table->foreignId('recommender_id')->nullable()->constrained('users')->nullOnDelete()->after('shop_listing_id');
            $table->string('recommendation_code')->nullable()->after('recommender_id');
            $table->foreignId('beneficiary_user_id')->nullable()->constrained('users')->nullOnDelete()->after('recommendation_code');
            $table->decimal('supplier_allocation_price', 10, 2)->default(0.00)->after('beneficiary_user_id');
            $table->decimal('gross_markup', 10, 2)->default(0.00)->after('supplier_allocation_price');
            $table->decimal('platform_fee', 10, 2)->default(0.00)->after('gross_markup');
            $table->decimal('seller_earning', 10, 2)->default(0.00)->after('platform_fee');
            $table->decimal('commission_amount', 10, 2)->default(0.00)->after('seller_earning');
            $table->json('commercial_terms_snapshot')->nullable()->after('commission_amount');

            $table->index(['community_shop_id', 'created_at']);
            $table->index(['beneficiary_user_id', 'earning_model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['community_shop_id']);
            $table->dropForeign(['shop_listing_id']);
            $table->dropForeign(['recommender_id']);
            $table->dropForeign(['beneficiary_user_id']);

            $table->dropColumn([
                'earning_model',
                'community_shop_id',
                'shop_listing_id',
                'recommender_id',
                'recommendation_code',
                'beneficiary_user_id',
                'supplier_allocation_price',
                'gross_markup',
                'platform_fee',
                'seller_earning',
                'commission_amount',
                'commercial_terms_snapshot',
            ]);
        });
    }
};
