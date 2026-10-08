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
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'channel')) {
                $table->string('channel', 20)->default('web')->index()->after('seller_id');
            }
            if (! Schema::hasColumn('orders', 'created_by_admin_id')) {
                $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete()->after('user_id');
            }
            if (! Schema::hasColumn('orders', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0.00)->after('discount_amount');
            }
            if (! Schema::hasColumn('orders', 'amount_received')) {
                $table->decimal('amount_received', 10, 2)->nullable()->after('total_amount');
            }
            if (! Schema::hasColumn('orders', 'change_amount')) {
                $table->decimal('change_amount', 10, 2)->nullable()->after('amount_received');
            }
            if (! Schema::hasColumn('orders', 'discount_reason')) {
                $table->string('discount_reason', 255)->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('orders', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->unique()->after('order_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $dropColumns = [];
            foreach (['channel', 'created_by_admin_id', 'tax_amount', 'amount_received', 'change_amount', 'discount_reason', 'idempotency_key'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $dropColumns[] = $col;
                }
            }
            if (! empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
