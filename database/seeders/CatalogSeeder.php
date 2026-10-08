<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 0. Seed Roles
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Administrator', 'description' => 'Operations and catalog management']
        );
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super_admin'],
            ['display_name' => 'Super Administrator', 'description' => 'Unrestricted authority']
        );
        Role::firstOrCreate(
            ['name' => 'seller'],
            ['display_name' => 'Merchant', 'description' => 'Local producer store operator']
        );
        Role::firstOrCreate(
            ['name' => 'curator'],
            ['display_name' => 'Curator', 'description' => 'Community taste curator']
        );

        // 1. Seed Platform Admin User
        $adminUser = User::firstOrCreate(
            ['phone' => '+8801700000000'],
            [
                'name' => 'JashoreBro Lead Admin',
                'username' => 'admin_jb',
                'password' => bcrypt('admin123456'),
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        if (! $adminUser->roles()->where('name', 'admin')->exists()) {
            $adminUser->roles()->attach($adminRole->id);
        }

        // Also assign admin role to the first registered user if any exists
        $firstUser = User::where('id', 1)->first();
        if ($firstUser && ! $firstUser->roles()->where('name', 'admin')->exists()) {
            $firstUser->roles()->attach($adminRole->id);
        }

        // Platform-owned Marketplace Seller
        $platformSeller = Seller::firstOrCreate(
            ['slug' => 'jashorebro-direct'],
            [
                'user_id' => $adminUser->id,
                'store_name' => 'JashoreBro Direct',
                'tagline' => 'Official JashoreBro Marketplace Fulfillment',
                'description' => 'Direct from the central warehouse at Doratana, Jashore. Authentic local goods with guaranteed purity and prompt regional dispatch.',
                'contact_phone' => '+8801700000000',
                'district' => 'Jashore',
                'upazila' => 'Jashore Sadar',
                'address' => 'Central Hub, Doratana, Jashore',
                'status' => 'active',
                'verified_at' => now(),
                'rating_avg' => 5.0,
                'rating_count' => 50,
            ]
        );

        // 2. Reusable Attributes & Items
        $attrColor = Attribute::firstOrCreate(
            ['slug' => 'color'],
            [
                'name' => 'Color',
                'type' => 'color',
                'sort_order' => 1,
                'is_active' => true,
                'is_filterable' => true,
                'is_variant' => true,
            ]
        );
        $itemBlack = $attrColor->items()->firstOrCreate(['value' => 'charcoal-black'], ['label' => 'Charcoal Black', 'color_code' => '#1a1a1a', 'sort_order' => 1]);
        $itemIndigo = $attrColor->items()->firstOrCreate(['value' => 'indigo-blue'], ['label' => 'Indigo Blue', 'color_code' => '#2e3a87', 'sort_order' => 2]);
        $itemOlive = $attrColor->items()->firstOrCreate(['value' => 'olive-green'], ['label' => 'Olive Green', 'color_code' => '#556b2f', 'sort_order' => 3]);
        $itemTerracotta = $attrColor->items()->firstOrCreate(['value' => 'terracotta'], ['label' => 'Terracotta Earth', 'color_code' => '#e2725b', 'sort_order' => 4]);

        $attrSize = Attribute::firstOrCreate(
            ['slug' => 'size'],
            [
                'name' => 'Size',
                'type' => 'button',
                'sort_order' => 2,
                'is_active' => true,
                'is_filterable' => true,
                'is_variant' => true,
            ]
        );
        $itemM = $attrSize->items()->firstOrCreate(['value' => 'M'], ['label' => 'Medium (M)', 'sort_order' => 1]);
        $itemL = $attrSize->items()->firstOrCreate(['value' => 'L'], ['label' => 'Large (L)', 'sort_order' => 2]);
        $itemXL = $attrSize->items()->firstOrCreate(['value' => 'XL'], ['label' => 'Extra Large (XL)', 'sort_order' => 3]);

        $attrWeight = Attribute::firstOrCreate(
            ['slug' => 'net-weight'],
            [
                'name' => 'Net Quantity',
                'type' => 'button',
                'sort_order' => 3,
                'is_active' => true,
                'is_filterable' => true,
                'is_variant' => true,
            ]
        );
        $item500g = $attrWeight->items()->firstOrCreate(['value' => '500g'], ['label' => '500g Fresh Pack', 'sort_order' => 1]);
        $item1kg = $attrWeight->items()->firstOrCreate(['value' => '1kg'], ['label' => '1kg Premium Slab', 'sort_order' => 2]);
        $item2kg = $attrWeight->items()->firstOrCreate(['value' => '2kg'], ['label' => '2kg Family Matka', 'sort_order' => 3]);

        // 3. Merchants
        $gurMerchantUser = User::firstOrCreate(
            ['phone' => '+8801711000001'],
            [
                'name' => 'Al-Haj Rafiqul Islam',
                'username' => 'rafiqul_keshabpur',
                'password' => bcrypt('password123'),
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        $sellerGur = Seller::firstOrCreate(
            ['slug' => 'keshabpur-heritage-gur'],
            [
                'user_id' => $gurMerchantUser->id,
                'store_name' => 'Keshabpur Heritage Gur (খেজুরের গুড়)',
                'tagline' => '100% Pure Chemical-free Traditional Date Palm Jaggery',
                'description' => 'Direct from the date palm orchards of Keshabpur.',
                'contact_phone' => '+8801711000001',
                'district' => 'Jashore',
                'upazila' => 'Keshabpur',
                'address' => 'Trimohini Road, Keshabpur, Jashore',
                'status' => 'active',
                'verified_at' => now(),
                'rating_avg' => 4.95,
                'rating_count' => 128,
            ]
        );

        $textileMerchantUser = User::firstOrCreate(
            ['phone' => '+8801711000002'],
            [
                'name' => 'Nasreen Akhtar',
                'username' => 'nasreen_crafts',
                'password' => bcrypt('password123'),
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        $sellerTextile = Seller::firstOrCreate(
            ['slug' => 'jashore-nakshi-crafts'],
            [
                'user_id' => $textileMerchantUser->id,
                'store_name' => 'Jashore Nakshi & Handloom Collective',
                'tagline' => 'Heritage Hand-stitched Nakshi Kantha & Khadi Apparels',
                'description' => 'A cooperative of over 45 artisan women in rural Jashore.',
                'contact_phone' => '+8801711000002',
                'district' => 'Jashore',
                'upazila' => 'Jashore Sadar',
                'address' => 'Rail Road, Jashore Sadar',
                'status' => 'active',
                'verified_at' => now(),
                'rating_avg' => 4.88,
                'rating_count' => 86,
            ]
        );

        // 4. Categories
        $catGur = Category::firstOrCreate(
            ['slug' => 'date-palm-jaggery'],
            [
                'name' => 'Date Palm Jaggery (খেজুরের গুড়)',
                'icon' => 'Flame',
                'description' => 'Famous seasonal patali gur and nolen gur from Jashore orchards.',
                'order_index' => 1,
            ]
        );
        $catTextile = Category::firstOrCreate(
            ['slug' => 'handloom-nakshi-kantha'],
            [
                'name' => 'Handloom & Nakshi Kantha (নকশী কাঁথা)',
                'icon' => 'Scissors',
                'description' => 'Hand-stitched quilts, handloom panjabi, and heritage textiles.',
                'order_index' => 2,
            ]
        );
        $catLifestyle = Category::firstOrCreate(
            ['slug' => 'community-gear'],
            [
                'name' => 'Community Gear & Streetwear',
                'icon' => 'ShoppingBag',
                'description' => 'Curated heavyweight hoodies, durable tote bags, and everyday carry essentials.',
                'order_index' => 3,
            ]
        );

        // 5. Products with full admin flags & variants
        $productsData = [
            [
                'seller_id' => $sellerGur->id,
                'category_id' => $catGur->id,
                'title' => 'Authentic Keshabpur Khejur Patali Gur (1kg Slab)',
                'slug' => 'authentic-keshabpur-khejur-patali-gur-1kg',
                'short_description' => '100% natural, unadulterated winter date palm jaggery with rich caramel aroma and signature softness.',
                'description' => 'Freshly tapped date palm sap from ancient trees in Keshabpur, slow-simmered over wood-fire clay kilns. No chemical whiteners or added sugar syrup.',
                'brand' => 'Keshabpur Heritage',
                'sku' => 'JB-GUR-PATALI-01',
                'barcode' => '894000100101',
                'status' => 'published',
                'visibility' => 'public',
                'currency' => 'BDT',
                'base_price' => 650.00,
                'compare_price' => 780.00,
                'cost_price' => 480.00,
                'stock_quantity' => 250,
                'track_inventory' => true,
                'low_stock_threshold' => 15,
                'min_order_quantity' => 1,
                'max_order_quantity' => 5,
                'is_normal_purchase_enabled' => true,
                'is_group_buy_enabled' => true,
                'is_featured' => true,
                'is_new_arrival' => false,
                'is_bestseller' => true,
                'is_cod_available' => true,
                'is_free_shipping' => false,
                'shipping_charge' => 60.00,
                'is_returnable' => true,
                'return_window_days' => 7,
                'is_cancelable' => true,
                'cancellation_cutoff_hours' => 24,
                'weight_kg' => 1.10,
                'dimensions' => ['length' => 20, 'width' => 15, 'height' => 5, 'unit' => 'cm'],
                'tags' => ['gur', 'keshabpur', 'date palm', 'traditional', 'pure'],
                'image' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => '500g Fresh Pack', 'sku' => 'JB-GUR-500G', 'price_override' => 350.00, 'stock' => 120, 'item_ids' => [$item500g->id]],
                    ['name' => '1kg Premium Slab', 'sku' => 'JB-GUR-1KG', 'price_override' => 650.00, 'stock' => 80, 'item_ids' => [$item1kg->id]],
                    ['name' => '2kg Family Matka', 'sku' => 'JB-GUR-2KG', 'price_override' => 1250.00, 'stock' => 50, 'item_ids' => [$item2kg->id]],
                ],
            ],
            [
                'seller_id' => $platformSeller->id,
                'category_id' => $catLifestyle->id,
                'title' => 'JashoreBro Heavyweight 380GSM Fleece Hoodie',
                'slug' => 'jashorebro-heavyweight-380gsm-fleece-hoodie',
                'short_description' => 'Custom woven heavyweight French terry cotton hoodie with embroidered chest insignia.',
                'description' => 'Tailored for winter gatherings across Jashore. Double-lined hood, kangaroo pocket with concealed key stash, and pre-shrunk premium finish.',
                'brand' => 'JashoreBro Originals',
                'sku' => 'JB-HOOD-380',
                'barcode' => '894000100201',
                'status' => 'published',
                'visibility' => 'public',
                'currency' => 'BDT',
                'base_price' => 1650.00,
                'compare_price' => 1950.00,
                'cost_price' => 1100.00,
                'stock_quantity' => 120,
                'track_inventory' => true,
                'low_stock_threshold' => 10,
                'min_order_quantity' => 1,
                'max_order_quantity' => 3,
                'is_normal_purchase_enabled' => true,
                'is_group_buy_enabled' => true,
                'is_featured' => true,
                'is_new_arrival' => true,
                'is_bestseller' => true,
                'is_cod_available' => true,
                'is_free_shipping' => true,
                'shipping_charge' => 0.00,
                'is_returnable' => true,
                'return_window_days' => 14,
                'is_cancelable' => true,
                'cancellation_cutoff_hours' => 24,
                'weight_kg' => 0.85,
                'dimensions' => ['length' => 35, 'width' => 28, 'height' => 6, 'unit' => 'cm'],
                'tags' => ['streetwear', 'hoodie', 'jashorebro', 'winter'],
                'image' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => 'Charcoal Black - Medium', 'sku' => 'JB-HOOD-BLK-M', 'price_override' => 1650.00, 'stock' => 30, 'item_ids' => [$itemBlack->id, $itemM->id]],
                    ['name' => 'Charcoal Black - Large', 'sku' => 'JB-HOOD-BLK-L', 'price_override' => 1650.00, 'stock' => 40, 'item_ids' => [$itemBlack->id, $itemL->id]],
                    ['name' => 'Olive Green - Large', 'sku' => 'JB-HOOD-OLV-L', 'price_override' => 1650.00, 'stock' => 30, 'item_ids' => [$itemOlive->id, $itemL->id]],
                    ['name' => 'Olive Green - XL', 'sku' => 'JB-HOOD-OLV-XL', 'price_override' => 1650.00, 'stock' => 20, 'item_ids' => [$itemOlive->id, $itemXL->id]],
                ],
            ],
            [
                'seller_id' => $sellerTextile->id,
                'category_id' => $catTextile->id,
                'title' => 'Hand-Stitched Jashore Heritage Nakshi Kantha (King Size)',
                'slug' => 'hand-stitched-jashore-heritage-nakshi-kantha-king',
                'short_description' => 'Masterpiece needlework quilt taking over 45 days of individual artisan stitching.',
                'description' => 'Made from soft multiple layers of unbleached breathable cotton. Detailed with traditional village motifs and folklore borders.',
                'brand' => 'Nakshi Collective',
                'sku' => 'JB-NK-KING-01',
                'barcode' => '894000100301',
                'status' => 'published',
                'visibility' => 'public',
                'currency' => 'BDT',
                'base_price' => 3200.00,
                'compare_price' => 3800.00,
                'cost_price' => 2400.00,
                'stock_quantity' => 35,
                'track_inventory' => true,
                'low_stock_threshold' => 5,
                'min_order_quantity' => 1,
                'max_order_quantity' => 2,
                'is_normal_purchase_enabled' => true,
                'is_group_buy_enabled' => false,
                'is_featured' => true,
                'is_new_arrival' => false,
                'is_bestseller' => false,
                'is_cod_available' => true,
                'is_free_shipping' => false,
                'shipping_charge' => 100.00,
                'is_returnable' => true,
                'return_window_days' => 7,
                'is_cancelable' => true,
                'cancellation_cutoff_hours' => 24,
                'weight_kg' => 2.40,
                'dimensions' => ['length' => 45, 'width' => 35, 'height' => 10, 'unit' => 'cm'],
                'tags' => ['nakshi kantha', 'heritage', 'handloom', 'artisan'],
                'image' => 'https://images.unsplash.com/photo-1607344645866-009c320c5ab8?w=800&auto=format&fit=crop&q=80',
                'variants' => [],
            ],
        ];

        foreach ($productsData as $data) {
            $variants = $data['variants'] ?? [];
            $image = $data['image'];
            unset($data['variants'], $data['image']);

            $product = Product::firstOrCreate(['slug' => $data['slug']], $data);

            // Add primary image
            $product->images()->firstOrCreate(
                ['image_url' => $image],
                ['alt_text' => $product->title, 'is_primary' => true, 'sort_order' => 0]
            );

            // Add variants and attribute item links
            foreach ($variants as $var) {
                $itemIds = $var['item_ids'] ?? [];
                unset($var['item_ids']);

                $variant = $product->variants()->firstOrCreate(
                    ['name' => $var['name']],
                    [
                        'sku' => $var['sku'],
                        'price_override' => $var['price_override'],
                        'stock_quantity' => $var['stock'],
                        'is_active' => true,
                    ]
                );

                if (! empty($itemIds)) {
                    $variant->attributeItems()->syncWithoutDetaching($itemIds);
                }
            }
        }

        // 6. Active Volume Drop Campaign
        $gurProduct = Product::where('slug', 'authentic-keshabpur-khejur-patali-gur-1kg')->first();
        if ($gurProduct) {
            $campaign = GroupBuyCampaign::firstOrCreate(
                ['campaign_code' => 'GRP-GUR-2026'],
                [
                    'product_id' => $gurProduct->id,
                    'title' => 'Keshabpur Khejur Gur Community Volume Drop',
                    'status' => 'active',
                    'group_price' => 540.00,
                    'target_participants' => 20,
                    'max_participants' => 50,
                    'quantity_limit_per_customer' => 2,
                    'start_at' => now()->subDay(),
                    'end_at' => now()->addDays(5),
                    'created_by' => $adminUser->id,
                ]
            );

            // Seed sample participant reservation
            $campaign->participants()->firstOrCreate(
                ['user_id' => $adminUser->id],
                [
                    'quantity' => 1,
                    'unit_price' => 540.00,
                    'status' => 'reserved',
                    'reserved_at' => now(),
                ]
            );
        }
    }
}
