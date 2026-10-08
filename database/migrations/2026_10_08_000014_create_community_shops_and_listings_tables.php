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
        Schema::create('community_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->string('status')->default('active'); // draft, active, suspended, archived
            $table->boolean('is_verified')->default(false);
            $table->text('notice')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('shop_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_shop_id')->constrained('community_shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('caption')->nullable();
            $table->decimal('selling_price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(['community_shop_id', 'product_id']);
            $table->index(['community_shop_id', 'is_active', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_listings');
        Schema::dropIfExists('community_shops');
    }
};
