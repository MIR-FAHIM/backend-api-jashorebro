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
        if (! Schema::hasTable('group_buy_campaigns')) {
            Schema::create('group_buy_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('campaign_code', 50)->unique();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->string('title');
                $table->string('status', 20)->default('draft'); // draft, scheduled, active, succeeded, failed, cancelled
                $table->decimal('group_price', 10, 2);
                $table->integer('target_participants'); // Distinct customers required
                $table->integer('max_participants')->nullable();
                $table->integer('quantity_limit_per_customer')->default(1);
                $table->timestamp('start_at')->nullable();
                $table->timestamp('end_at')->nullable();
                $table->timestamp('success_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('group_buy_participants')) {
            Schema::create('group_buy_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('group_buy_campaigns')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 10, 2);
                $table->string('status', 20)->default('reserved'); // reserved, confirmed, cancelled, released
                $table->timestamp('reserved_at')->useCurrent();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['campaign_id', 'user_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_buy_participants');
        Schema::dropIfExists('group_buy_campaigns');
    }
};
