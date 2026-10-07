<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
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
        // 1. Create Sellers / Merchants
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
        $gurMerchantUser->profile()->firstOrCreate([], [
            'bio' => '3rd generation date-palm jaggery maker in Keshabpur, Jashore.',
            'locality' => 'Keshabpur',
            'district' => 'Jashore',
        ]);

        $sellerGur = Seller::firstOrCreate(
            ['slug' => 'keshabpur-heritage-gur'],
            [
                'user_id' => $gurMerchantUser->id,
                'store_name' => 'Keshabpur Heritage Gur (খেজুরের গুড়)',
                'tagline' => '100% Pure Chemical-free Traditional Date Palm Jaggery',
                'description' => 'Direct from the date palm orchards of Keshabpur. Handcrafted using traditional clay pans without artificial colors or sugar syrups.',
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
        $textileMerchantUser->profile()->firstOrCreate([], [
            'bio' => 'Reviving authentic Nakshi Kantha artisans across Jashore & Noapara.',
            'locality' => 'Jashore Sadar',
            'district' => 'Jashore',
        ]);

        $sellerTextile = Seller::firstOrCreate(
            ['slug' => 'jashore-nakshi-crafts'],
            [
                'user_id' => $textileMerchantUser->id,
                'store_name' => 'Jashore Nakshi & Handloom Collective',
                'tagline' => 'Heritage Hand-stitched Nakshi Kantha & Khadi Apparels',
                'description' => 'A cooperative of over 45 artisan women in rural Jashore preserving centuries of hand-embroidery heritage.',
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

        $flowerMerchantUser = User::firstOrCreate(
            ['phone' => '+8801711000003'],
            [
                'name' => 'Monirul Hossain',
                'username' => 'gadkhali_florals',
                'password' => bcrypt('password123'),
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        $flowerMerchantUser->profile()->firstOrCreate([], [
            'bio' => 'Gadkhali flower capital botanical grower and pure distiller.',
            'locality' => 'Jhikargacha',
            'district' => 'Jashore',
        ]);

        $sellerFlora = Seller::firstOrCreate(
            ['slug' => 'gadkhali-botanics'],
            [
                'user_id' => $flowerMerchantUser->id,
                'store_name' => 'Gadkhali Botanics (গদখালি এসেন্স)',
                'tagline' => 'Fresh Distilled Floral Water & Natural Attar from the Flower Capital',
                'description' => 'Located in Gadkhali, the flower capital of Bangladesh. Pure steam-distilled floral water and attars crafted directly from fresh morning harvests.',
                'contact_phone' => '+8801711000003',
                'district' => 'Jashore',
                'upazila' => 'Jhikargacha',
                'address' => 'Gadkhali Flower Market, Jhikargacha, Jashore',
                'status' => 'active',
                'verified_at' => now(),
                'rating_avg' => 4.90,
                'rating_count' => 94,
            ]
        );

        $honeyMerchantUser = User::firstOrCreate(
            ['phone' => '+8801711000004'],
            [
                'name' => 'Tareq Mahmud',
                'username' => 'tareq_organics',
                'password' => bcrypt('password123'),
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );

        $sellerOrganics = Seller::firstOrCreate(
            ['slug' => 'sundarban-hive-organics'],
            [
                'user_id' => $honeyMerchantUser->id,
                'store_name' => 'Sundarban Raw Hive & Dairy',
                'tagline' => 'Wild Khalsi Honey & Traditional Bilona Cow Ghee',
                'description' => 'Unpasteurized forest honey gathered ethically with traditional Mouwals, paired with cultured desi grass-fed cow ghee.',
                'contact_phone' => '+8801711000004',
                'district' => 'Jashore',
                'upazila' => 'Manirampur',
                'address' => 'Dhapatala, Manirampur, Jashore',
                'status' => 'active',
                'verified_at' => now(),
                'rating_avg' => 4.92,
                'rating_count' => 110,
            ]
        );

        // 2. Categories
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

        $catFlora = Category::firstOrCreate(
            ['slug' => 'gadkhali-flowers-essence'],
            [
                'name' => 'Gadkhali Flowers & Extracts (ফুলের সুবাস)',
                'icon' => 'Sparkles',
                'description' => 'Pure rose water, seasonal blossoms, and natural attars from Gadkhali.',
                'order_index' => 3,
            ]
        );

        $catOrganics = Category::firstOrCreate(
            ['slug' => 'organic-honey-groceries'],
            [
                'name' => 'Pure Honey & Organic Pantry (খাঁটি মধু ও ঘি)',
                'icon' => 'CheckCircle',
                'description' => 'Sundarban wild honey, pure cow ghee, and pesticide-free grains.',
                'order_index' => 4,
            ]
        );

        $catLifestyle = Category::firstOrCreate(
            ['slug' => 'community-gear'],
            [
                'name' => 'Community Gear & Streetwear',
                'icon' => 'ShoppingBag',
                'description' => 'Curated heavyweight hoodies, durable tote bags, and everyday carry essentials.',
                'order_index' => 5,
            ]
        );

        // 3. Products
        $productsData = [
            [
                'seller_id' => $sellerGur->id,
                'category_id' => $catGur->id,
                'title' => 'Authentic Keshabpur Khejur Patali Gur (1kg Slab)',
                'slug' => 'authentic-keshabpur-khejur-patali-gur-1kg',
                'short_description' => '100% natural, unadulterated winter date palm jaggery with rich caramel aroma and signature softness.',
                'description' => 'Freshly tapped date palm sap from ancient trees in Keshabpur, slow-simmered over wood-fire clay kilns. No chemical whiteners or added sugar syrup.',
                'base_price' => 650,
                'compare_price' => 780,
                'stock_quantity' => 250,
                'is_featured' => true,
                'is_drop_ready' => true,
                'rating_avg' => 4.96,
                'rating_count' => 142,
                'image' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => '500g Fresh Pack', 'price_override' => 350, 'stock' => 120],
                    ['name' => '1kg Premium Earthen Clay Pot', 'price_override' => 720, 'stock' => 80],
                    ['name' => '2kg Family Bundle', 'price_override' => 1250, 'stock' => 50],
                ],
            ],
            [
                'seller_id' => $sellerGur->id,
                'category_id' => $catGur->id,
                'title' => 'Pure Liquid Nolen Gur in Traditional Clay Matka (1kg)',
                'slug' => 'pure-liquid-nolen-gur-clay-matka-1kg',
                'short_description' => 'Thick, fragrant first-harvest liquid date molasses in a breathable clay container.',
                'description' => 'The absolute pride of winter mornings. Perfect for making traditional pitha, payesh, and pairing with hot ruti.',
                'base_price' => 850,
                'compare_price' => 990,
                'stock_quantity' => 180,
                'is_featured' => true,
                'is_drop_ready' => true,
                'rating_avg' => 4.98,
                'rating_count' => 89,
                'image' => 'https://images.unsplash.com/photo-1589301760014-d929f3979dbc?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => '1kg Earthen Matka', 'price_override' => 850, 'stock' => 100],
                ],
            ],
            [
                'seller_id' => $sellerTextile->id,
                'category_id' => $catTextile->id,
                'title' => 'Hand-Stitched Jashore Heritage Nakshi Kantha (King Size)',
                'slug' => 'hand-stitched-jashore-heritage-nakshi-kantha-king',
                'short_description' => 'Masterpiece needlework quilt taking over 45 days of individual artisan stitching.',
                'description' => 'Made from soft multiple layers of unbleached breathable cotton. Detailed with traditional village motifs, lotus mandalas, and folklore borders.',
                'base_price' => 3200,
                'compare_price' => 3800,
                'stock_quantity' => 35,
                'is_featured' => true,
                'is_drop_ready' => true,
                'rating_avg' => 4.92,
                'rating_count' => 64,
                'image' => 'https://images.unsplash.com/photo-1607344645866-009c320c5ab8?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => 'Indigo & Crimson Motif', 'price_override' => 3200, 'stock' => 15],
                    ['name' => 'Mustard & Earth Terracotta', 'price_override' => 3200, 'stock' => 20],
                ],
            ],
            [
                'seller_id' => $sellerFlora->id,
                'category_id' => $catFlora->id,
                'title' => 'Gadkhali Organic Steam-Distilled Rose Water (200ml)',
                'slug' => 'gadkhali-organic-steam-distilled-rose-water-200ml',
                'short_description' => 'Pure hydrosol mist distilled from freshly plucked Gadkhali Damask roses at sunrise.',
                'description' => 'Zero alcohol, zero preservatives. A revitalizing facial mist, culinary enhancer, and soothing natural skin balancer.',
                'base_price' => 450,
                'compare_price' => 550,
                'stock_quantity' => 300,
                'is_featured' => true,
                'is_drop_ready' => true,
                'rating_avg' => 4.88,
                'rating_count' => 77,
                'image' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => '200ml Amber Spray Bottle', 'price_override' => 450, 'stock' => 200],
                    ['name' => '500ml Refill Glass Bottle', 'price_override' => 950, 'stock' => 100],
                ],
            ],
            [
                'seller_id' => $sellerOrganics->id,
                'category_id' => $catOrganics->id,
                'title' => 'Wild Sundarban Khalsi Blossom Honey (1kg Glass Jar)',
                'slug' => 'wild-sundarban-khalsi-blossom-honey-1kg',
                'short_description' => 'Raw, unprocessed wild mangrove forest honey with delicate floral undertones.',
                'description' => 'Harvested directly from deep mangrove zones during Khalsi flower blooming season. Raw, unfiltered, non-heated to retain live enzymes.',
                'base_price' => 1400,
                'compare_price' => 1650,
                'stock_quantity' => 150,
                'is_featured' => true,
                'is_drop_ready' => true,
                'rating_avg' => 4.97,
                'rating_count' => 112,
                'image' => 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => '500g Jar', 'price_override' => 750, 'stock' => 80],
                    ['name' => '1kg Jar', 'price_override' => 1400, 'stock' => 70],
                ],
            ],
            [
                'seller_id' => $sellerTextile->id,
                'category_id' => $catLifestyle->id,
                'title' => 'JashoreBro Heavyweight 380GSM Fleece Hoodie',
                'slug' => 'jashorebro-heavyweight-380gsm-fleece-hoodie',
                'short_description' => 'Custom woven heavyweight French terry cotton hoodie with embroidered chest insignia.',
                'description' => 'Tailored for winter gatherings. Double-lined hood, kangaroo pocket with concealed key stash, and pre-shrunk premium finish.',
                'base_price' => 1650,
                'compare_price' => 1950,
                'stock_quantity' => 120,
                'is_featured' => true,
                'is_drop_ready' => true,
                'rating_avg' => 4.90,
                'rating_count' => 58,
                'image' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=800&auto=format&fit=crop&q=80',
                'variants' => [
                    ['name' => 'Charcoal Heather - Medium', 'price_override' => 1650, 'stock' => 30],
                    ['name' => 'Charcoal Heather - Large', 'price_override' => 1650, 'stock' => 40],
                    ['name' => 'Olive Green - Large', 'price_override' => 1650, 'stock' => 30],
                    ['name' => 'Olive Green - XL', 'price_override' => 1650, 'stock' => 20],
                ],
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

            // Add variants
            foreach ($variants as $var) {
                $product->variants()->firstOrCreate(
                    ['name' => $var['name']],
                    [
                        'price_override' => $var['price_override'],
                        'stock_quantity' => $var['stock'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
