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
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number', 50)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
                $table->string('status', 30)->default('pending'); // pending, processing, shipped, delivered, cancelled
                $table->string('payment_status', 30)->default('unpaid'); // unpaid, paid, refunded
                $table->string('payment_method', 30)->default('cod'); // cod, bkash, nagad
                $table->decimal('subtotal', 10, 2);
                $table->decimal('shipping_fee', 10, 2)->default(0.00);
                $table->decimal('discount_amount', 10, 2)->default(0.00);
                $table->decimal('total_amount', 10, 2);

                // Delivery information
                $table->string('shipping_name');
                $table->string('shipping_phone', 30);
                $table->string('shipping_district')->default('Jashore');
                $table->string('shipping_upazila');
                $table->text('shipping_address');
                $table->text('notes')->nullable();

                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->string('purchase_mode', 20)->default('normal'); // normal, group_buy
                $table->foreignId('group_buy_campaign_id')->nullable()->constrained('group_buy_campaigns')->nullOnDelete();

                // Immutable snapshots
                $table->string('product_title');
                $table->string('product_sku')->nullable();
                $table->string('variant_name')->nullable();
                $table->json('variant_attributes')->nullable();
                $table->decimal('unit_price', 10, 2);
                $table->integer('quantity')->default(1);
                $table->decimal('line_total', 10, 2);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
