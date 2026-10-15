<?php

use App\Models\Category;
use App\Models\CommunityShop;
use App\Models\Product;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
    $this->admin->roles()->sync([$role->id]);

    $seller = Seller::create([
        'user_id' => $this->admin->id,
        'store_name' => 'Marketplace Supplier',
        'slug' => 'marketplace-supplier',
        'contact_phone' => '+8801711000111',
        'district' => 'Jashore',
        'status' => 'active',
    ]);
    $category = Category::create([
        'name' => 'Handloom',
        'slug' => 'handloom',
        'is_active' => true,
    ]);
    $this->product = Product::create([
        'seller_id' => $seller->id,
        'category_id' => $category->id,
        'title' => 'Resale Kantha',
        'slug' => 'resale-kantha',
        'base_price' => 1500,
        'stock_quantity' => 20,
        'status' => 'published',
        'visibility' => 'public',
        'is_community_shop_enabled' => true,
        'supplier_allocation_price' => 700,
        'min_selling_price' => 900,
        'max_selling_price' => 1500,
        'platform_fee_percent' => 12.5,
    ]);
    $this->product->images()->create([
        'image_url' => 'https://example.test/resale-kantha.jpg',
        'is_primary' => true,
    ]);

    $this->shopOwner = User::factory()->create(['status' => 'active']);
    $this->shop = CommunityShop::create([
        'user_id' => $this->shopOwner->id,
        'name' => 'My Kantha Shop',
        'slug' => 'my-kantha-shop',
        'status' => 'active',
    ]);
    $this->termsUrl = "/api/shops/get-product-resale-terms/{$this->product->id}";
});

test('saved admin resale settings are returned privately and accepted when listing', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/admin/products/update-product/{$this->product->id}", [
            'is_community_shop_enabled' => true,
            'supplier_allocation_price' => 650,
            'min_selling_price' => 800,
            'max_selling_price' => 1400,
            'platform_fee_percent' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.supplier_allocation_price', 650);

    $this->getJson("/api/admin/products/get-product/{$this->product->id}")
        ->assertOk()
        ->assertJsonPath('data.max_selling_price', 1400);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'data' => [
                'is_enabled' => true,
                'is_configured' => true,
                'supplier_allocation_price' => 650,
                'min_selling_price' => 800,
                'max_selling_price' => 1400,
                'platform_fee_percent' => 5,
                'reason' => null,
                'message' => null,
            ],
        ]);

    $this->postJson('/api/shops/add-shop-listing', [
        'product_id' => $this->product->id,
        'selling_price' => 1000,
    ])
        ->assertCreated()
        ->assertJsonPath('data.commercial_breakdown.supplier_allocation_price', 650)
        ->assertJsonPath('data.commercial_breakdown.platform_fee', 17.5)
        ->assertJsonPath('data.commercial_breakdown.terms_snapshot.min_selling_price', 800)
        ->assertJsonPath('data.commercial_breakdown.terms_snapshot.max_selling_price', 1400)
        ->assertJsonPath('data.commercial_breakdown.terms_snapshot.platform_fee_percent', 5);

    $this->assertDatabaseHas('shop_listings', [
        'community_shop_id' => $this->shop->id,
        'product_id' => $this->product->id,
        'selling_price' => 1000,
    ]);
});

test('missing commercial terms remain null and block preview and listing consistently', function (array $changes, string $reason, string $missingField) {
    $this->product->update($changes);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertOk()
        ->assertJsonPath('data.is_enabled', true)
        ->assertJsonPath('data.is_configured', false)
        ->assertJsonPath('data.reason', $reason)
        ->assertJsonPath("data.{$missingField}", null);

    $this->postJson('/api/shops/add-shop-listing', [
        'product_id' => $this->product->id,
        'selling_price' => 1000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('selling_price');

    $this->assertDatabaseCount('shop_listings', 0);
})->with([
    'supplier allocation' => [
        ['supplier_allocation_price' => null, 'cost_price' => null, 'min_selling_price' => null],
        'missing_supplier_allocation',
        'supplier_allocation_price',
    ],
    'maximum price' => [
        ['max_selling_price' => null],
        'missing_max_price',
        'max_selling_price',
    ],
]);

test('private terms preserve zero allocation minimum and fee through listing', function () {
    $this->product->update([
        'supplier_allocation_price' => 0,
        'cost_price' => 700,
        'min_selling_price' => 0,
        'platform_fee_percent' => 0,
    ]);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertOk()
        ->assertJsonPath('data.is_configured', true)
        ->assertJsonPath('data.supplier_allocation_price', 0)
        ->assertJsonPath('data.min_selling_price', 0)
        ->assertJsonPath('data.platform_fee_percent', 0);

    $this->postJson('/api/shops/add-shop-listing', [
        'product_id' => $this->product->id,
        'selling_price' => 100,
    ])
        ->assertCreated()
        ->assertJsonPath('data.commercial_breakdown.supplier_allocation_price', 0)
        ->assertJsonPath('data.commercial_breakdown.platform_fee', 0)
        ->assertJsonPath('data.commercial_breakdown.terms_snapshot.min_selling_price', 0);
});

test('private resale terms use the existing cost and minimum fallbacks without exposing raw cost', function () {
    $this->product->update([
        'supplier_allocation_price' => null,
        'cost_price' => 425,
        'min_selling_price' => null,
    ]);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertOk()
        ->assertJsonPath('data.is_configured', true)
        ->assertJsonPath('data.supplier_allocation_price', 425)
        ->assertJsonPath('data.min_selling_price', 425)
        ->assertJsonMissingPath('data.cost_price');

    $this->postJson('/api/shops/add-shop-listing', [
        'product_id' => $this->product->id,
        'selling_price' => 500,
    ])
        ->assertCreated()
        ->assertJsonPath('data.commercial_breakdown.supplier_allocation_price', 425)
        ->assertJsonPath('data.commercial_breakdown.platform_fee', 9.38);
});

test('disabled resale returns an explicit disabled state and cannot be listed', function () {
    $this->product->update(['is_community_shop_enabled' => false]);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertOk()
        ->assertJsonPath('data.is_enabled', false)
        ->assertJsonPath('data.is_configured', false)
        ->assertJsonPath('data.reason', 'disabled')
        ->assertJsonPath('data.supplier_allocation_price', null);

    $this->postJson('/api/shops/add-shop-listing', [
        'product_id' => $this->product->id,
        'selling_price' => 1000,
    ])->assertUnprocessable()->assertJsonValidationErrors('product_id');
});

test('invalid resale price bounds block both terms preview and listing', function () {
    $this->product->update(['min_selling_price' => 1600, 'max_selling_price' => 1500]);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertOk()
        ->assertJsonPath('data.is_configured', false)
        ->assertJsonPath('data.reason', 'invalid_price_bounds');

    $this->postJson('/api/shops/add-shop-listing', [
        'product_id' => $this->product->id,
        'selling_price' => 1500,
    ])->assertUnprocessable()->assertJsonValidationErrors('selling_price');
});

test('resale terms require authentication and the current users own shop', function () {
    $this->getJson($this->termsUrl)->assertUnauthorized()->assertJsonMissingPath('data.supplier_allocation_price');

    $otherUser = User::factory()->create(['status' => 'active']);
    $this->actingAs($otherUser, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertNotFound()
        ->assertJsonPath('message', 'Create your own community shop before viewing product resale terms.')
        ->assertJsonMissingPath('data.supplier_allocation_price');
});

test('private resale terms reject products unavailable in the public catalog', function (array $changes) {
    $this->product->update($changes);

    $this->actingAs($this->shopOwner, 'sanctum')
        ->getJson($this->termsUrl)
        ->assertNotFound()
        ->assertJsonMissingPath('data.supplier_allocation_price');
})->with([
    'hidden' => [['visibility' => 'hidden']],
    'draft' => [['status' => 'draft']],
    'archived' => [['status' => 'archived']],
]);

test('public product responses expose eligibility without private resale pricing', function () {
    foreach (['/api/products/get-products', '/api/products/get-product/resale-kantha'] as $url) {
        $response = $this->getJson($url)->assertOk();
        $product = $url === '/api/products/get-products' ? $response->json('data.0') : $response->json('data');

        expect($product['is_community_shop_enabled'])->toBeTrue();
        foreach (['cost_price', 'supplier_allocation_price', 'min_selling_price', 'max_selling_price', 'platform_fee_percent'] as $field) {
            expect($product)->not->toHaveKey($field);
        }
    }
});
