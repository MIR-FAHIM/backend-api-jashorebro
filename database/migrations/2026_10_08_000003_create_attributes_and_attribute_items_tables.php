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
        if (! Schema::hasTable('attributes')) {
            Schema::create('attributes', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->string('type', 30)->default('button'); // button, color, select, radio
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_filterable')->default(true);
                $table->boolean('is_required')->default(false);
                $table->boolean('is_variant')->default(true); // true = purchasable option, false = specification
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attribute_items')) {
            Schema::create('attribute_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
                $table->string('label', 100);
                $table->string('value', 100);
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('color_code', 30)->nullable(); // e.g. #000000
                $table->string('image_url')->nullable();
                $table->timestamps();

                $table->unique(['attribute_id', 'value']);
            });
        }

        if (! Schema::hasTable('product_attribute_values')) {
            Schema::create('product_attribute_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
                $table->foreignId('attribute_item_id')->nullable()->constrained('attribute_items')->nullOnDelete();
                $table->string('custom_value')->nullable(); // For text-based specification attributes
                $table->boolean('is_variant_option')->default(false);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('attribute_items');
        Schema::dropIfExists('attributes');
    }
};
