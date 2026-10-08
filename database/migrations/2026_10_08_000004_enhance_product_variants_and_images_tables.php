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
        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'compare_price')) {
                $table->decimal('compare_price', 10, 2)->nullable()->after('price_override');
            }
            if (! Schema::hasColumn('product_variants', 'group_price')) {
                $table->decimal('group_price', 10, 2)->nullable()->after('compare_price');
            }
            if (! Schema::hasColumn('product_variants', 'barcode')) {
                $table->string('barcode')->nullable()->after('sku');
            }
            if (! Schema::hasColumn('product_variants', 'image_id')) {
                $table->foreignId('image_id')->nullable()->after('barcode')->constrained('product_images')->nullOnDelete();
            }
            if (! Schema::hasColumn('product_variants', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        if (! Schema::hasTable('product_variant_attribute_items')) {
            Schema::create('product_variant_attribute_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('attribute_item_id')->constrained('attribute_items')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['product_variant_id', 'attribute_item_id'], 'var_attr_unique');
            });
        }

        Schema::table('product_images', function (Blueprint $table) {
            if (! Schema::hasColumn('product_images', 'variant_id')) {
                $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }
            if (! Schema::hasColumn('product_images', 'file_path')) {
                $table->string('file_path')->nullable()->after('image_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_items');

        Schema::table('product_images', function (Blueprint $table) {
            if (Schema::hasColumn('product_images', 'variant_id')) {
                $table->dropForeign(['variant_id']);
                $table->dropColumn('variant_id');
            }
            if (Schema::hasColumn('product_images', 'file_path')) {
                $table->dropColumn('file_path');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (Schema::hasColumn('product_variants', 'image_id')) {
                $table->dropForeign(['image_id']);
                $table->dropColumn('image_id');
            }
            $cols = ['compare_price', 'group_price', 'barcode', 'deleted_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('product_variants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
