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
            if (! Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->after('category_id');
            }
            if (! Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode')->nullable()->after('sku');
            }
            if (! Schema::hasColumn('products', 'status')) {
                $table->string('status', 20)->default('draft')->after('is_active'); // draft, published, archived
            }
            if (! Schema::hasColumn('products', 'visibility')) {
                $table->string('visibility', 20)->default('public')->after('status'); // public, hidden
            }
            if (! Schema::hasColumn('products', 'currency')) {
                $table->string('currency', 10)->default('BDT')->after('visibility');
            }
            if (! Schema::hasColumn('products', 'track_inventory')) {
                $table->boolean('track_inventory')->default(true)->after('stock_quantity');
            }
            if (! Schema::hasColumn('products', 'low_stock_threshold')) {
                $table->integer('low_stock_threshold')->default(5)->after('track_inventory');
            }
            if (! Schema::hasColumn('products', 'min_order_quantity')) {
                $table->integer('min_order_quantity')->default(1)->after('low_stock_threshold');
            }
            if (! Schema::hasColumn('products', 'max_order_quantity')) {
                $table->integer('max_order_quantity')->nullable()->default(10)->after('min_order_quantity');
            }

            // Behavioral & Purchase mode flags
            if (! Schema::hasColumn('products', 'is_normal_purchase_enabled')) {
                $table->boolean('is_normal_purchase_enabled')->default(true)->after('max_order_quantity');
            }
            if (! Schema::hasColumn('products', 'is_group_buy_enabled')) {
                $table->boolean('is_group_buy_enabled')->default(false)->after('is_normal_purchase_enabled');
            }
            if (! Schema::hasColumn('products', 'is_new_arrival')) {
                $table->boolean('is_new_arrival')->default(false)->after('is_group_buy_enabled');
            }
            if (! Schema::hasColumn('products', 'is_bestseller')) {
                $table->boolean('is_bestseller')->default(false)->after('is_new_arrival');
            }
            if (! Schema::hasColumn('products', 'is_inventory_tracked')) {
                $table->boolean('is_inventory_tracked')->default(true)->after('is_bestseller');
            }
            if (! Schema::hasColumn('products', 'is_cod_available')) {
                $table->boolean('is_cod_available')->default(true)->after('is_inventory_tracked');
            }
            if (! Schema::hasColumn('products', 'is_free_shipping')) {
                $table->boolean('is_free_shipping')->default(false)->after('is_cod_available');
            }
            if (! Schema::hasColumn('products', 'is_returnable')) {
                $table->boolean('is_returnable')->default(true)->after('is_free_shipping');
            }
            if (! Schema::hasColumn('products', 'return_window_days')) {
                $table->integer('return_window_days')->default(7)->after('is_returnable');
            }
            if (! Schema::hasColumn('products', 'is_cancelable')) {
                $table->boolean('is_cancelable')->default(true)->after('return_window_days');
            }
            if (! Schema::hasColumn('products', 'cancellation_cutoff_hours')) {
                $table->integer('cancellation_cutoff_hours')->default(24)->after('is_cancelable');
            }

            // Shipping specifications
            if (! Schema::hasColumn('products', 'weight_kg')) {
                $table->decimal('weight_kg', 8, 2)->nullable()->after('cancellation_cutoff_hours');
            }
            if (! Schema::hasColumn('products', 'dimensions')) {
                $table->json('dimensions')->nullable()->after('weight_kg');
            }
            if (! Schema::hasColumn('products', 'shipping_charge')) {
                $table->decimal('shipping_charge', 10, 2)->default(60.00)->after('dimensions');
            }

            // Server-side audit ownership
            if (! Schema::hasColumn('products', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('shipping_charge')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('products', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columnsToDrop = [
                'brand', 'barcode', 'status', 'visibility', 'currency',
                'track_inventory', 'low_stock_threshold', 'min_order_quantity', 'max_order_quantity',
                'is_normal_purchase_enabled', 'is_group_buy_enabled', 'is_new_arrival', 'is_bestseller',
                'is_inventory_tracked', 'is_cod_available', 'is_free_shipping',
                'is_returnable', 'return_window_days', 'is_cancelable', 'cancellation_cutoff_hours',
                'weight_kg', 'dimensions', 'shipping_charge', 'created_by', 'updated_by',
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
