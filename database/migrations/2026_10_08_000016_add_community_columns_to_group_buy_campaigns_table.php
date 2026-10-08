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
        Schema::table('group_buy_campaigns', function (Blueprint $table) {
            $table->foreignId('organizer_user_id')->nullable()->constrained('users')->nullOnDelete()->after('created_by_user_id');
            $table->foreignId('originating_shop_id')->nullable()->constrained('community_shops')->nullOnDelete()->after('organizer_user_id');
            $table->decimal('organizer_commission_per_unit', 10, 2)->default(0.00)->after('group_price');
            $table->decimal('supplier_allocation_price', 10, 2)->default(0.00)->after('organizer_commission_per_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_buy_campaigns', function (Blueprint $table) {
            $table->dropForeign(['organizer_user_id']);
            $table->dropForeign(['originating_shop_id']);

            $table->dropColumn([
                'organizer_user_id',
                'originating_shop_id',
                'organizer_commission_per_unit',
                'supplier_allocation_price',
            ]);
        });
    }
};
