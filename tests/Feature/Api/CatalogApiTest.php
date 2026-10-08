<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;

test('can list categories with active status and counts', function () {
    $parent = Category::create([
        'name' => 'Artisan Sweets',
        'slug' => 'artisan-sweets',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/categories');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('can search and filter products by category and search keyword', function () {
    $user = User::factory()->create();
    $seller = Seller::create([
        'user_id' => $user->id,
        'store_name' => 'Keshabpur Gur Emporium',
        'slug' => 'keshabpur-gur-emporium',
        'contact_phone' => '+8801711122233',
        'status' => 'active',
        'district' => 'Jashore',
        'upazila' => 'Keshabpur',
    ]);

    $cat = Category::create([
        'name' => 'Date Palm Jaggery',
        'slug' => 'date-palm-jaggery-test',
        'is_active' => true,
    ]);

    $product = Product::create([
        'seller_id' => $seller->id,
        'category_id' => $cat->id,
        'title' => 'Pure Patali Gur',
        'slug' => 'pure-patali-gur-test',
        'base_price' => 650,
        'stock_quantity' => 50,
        'status' => 'published',
        'is_active' => true,
        'is_featured' => true,
        'is_drop_ready' => true,
    ]);

    // Query with search
    $searchResponse = $this->getJson('/api/products?q=Patali');
    $searchResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.slug', 'pure-patali-gur-test');

    // Query single product
    $detailResponse = $this->getJson('/api/products/pure-patali-gur-test');
    $detailResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Pure Patali Gur');
});

test('authenticated user can apply to become a merchant', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/sellers/apply', [
        'store_name' => 'Jashore Organic Farm',
        'contact_phone' => '01712345678',
        'upazila' => 'Chaugachha',
        'district' => 'Jashore',
        'address' => 'Vill: Patibila, Chaugachha',
        'description' => 'Organic vegetables and native rice varieties.',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('sellers', [
        'user_id' => $user->id,
        'store_name' => 'Jashore Organic Farm',
        'status' => 'pending',
    ]);
});
