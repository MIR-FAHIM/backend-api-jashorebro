<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CommunityShop;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\MediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaUrlResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_url_resolves_localhost_to_live_base_url(): void
    {
        $input = 'http://localhost/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png';
        $expected = 'https://backend.jashorebro.com/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png';

        $this->assertSame($expected, MediaUrl::resolve($input));
    }

    public function test_media_url_resolves_localhost_with_port(): void
    {
        $input = 'http://localhost:8000/storage/products/4/test.png';
        $expected = 'https://backend.jashorebro.com/storage/products/4/test.png';

        $this->assertSame($expected, MediaUrl::resolve($input));
    }

    public function test_media_url_resolves_relative_paths(): void
    {
        $this->assertSame(
            'https://backend.jashorebro.com/storage/products/4/test.png',
            MediaUrl::resolve('/storage/products/4/test.png')
        );

        $this->assertSame(
            'https://backend.jashorebro.com/storage/products/4/test.png',
            MediaUrl::resolve(null, 'products/4/test.png')
        );
    }

    public function test_product_image_model_automatically_transforms_legacy_localhost(): void
    {
        $image = new ProductImage([
            'product_id' => 4,
            'image_url' => 'http://localhost/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png',
            'file_path' => 'products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png',
            'alt_text' => 'Cooker',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $this->assertSame(
            'https://backend.jashorebro.com/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png',
            $image->image_url
        );
        $this->assertSame(
            'https://backend.jashorebro.com/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png',
            $image->thumbnail_url
        );

        $array = $image->toArray();
        $this->assertSame(
            'https://backend.jashorebro.com/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png',
            $array['image_url']
        );
        $this->assertSame(
            'https://backend.jashorebro.com/storage/products/4/7YXsxjfORt22ZfupehSjRwqlI6rClpSSW6LvLpc8.png',
            $array['thumbnail_url']
        );
    }

    public function test_category_and_shop_models_normalize_image_urls(): void
    {
        $category = Category::create([
            'name' => 'Appliances',
            'slug' => 'appliances',
            'image_url' => 'http://localhost/storage/categories/appliances.png',
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->assertSame(
            'https://backend.jashorebro.com/storage/categories/appliances.png',
            $category->image_url
        );

        $user = User::factory()->create();
        $shop = CommunityShop::create([
            'user_id' => $user->id,
            'name' => 'Fahim Store',
            'slug' => 'fahim-store',
            'logo_url' => 'http://localhost/storage/shops/logo.png',
            'banner_url' => 'http://localhost/storage/shops/banner.png',
            'status' => 'active',
        ]);

        $this->assertSame('https://backend.jashorebro.com/storage/shops/logo.png', $shop->logo_url);
        $this->assertSame('https://backend.jashorebro.com/storage/shops/banner.png', $shop->banner_url);
    }
}
