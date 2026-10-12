<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;

beforeEach(function () {
    $this->adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->roles()->sync([$this->adminRole->id]);

    $this->regularUser = User::factory()->create(['status' => 'active']);
});

test('unauthorized users cannot access admin category routes', function () {
    $this->actingAs($this->regularUser, 'sanctum')
        ->getJson('/api/admin/categories/get-categories')
        ->assertStatus(403);

    $this->actingAs($this->regularUser, 'sanctum')
        ->postJson('/api/admin/categories/add-category', ['name' => 'Forbidden'])
        ->assertStatus(403);
});

test('admin can create root and child categories', function () {
    // 1. Create root category
    $rootResponse = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/admin/categories/add-category', [
            'name' => 'Electronics & Gadgets',
            'icon' => 'Smartphone',
            'order_index' => 1,
            'is_active' => true,
        ]);

    $rootResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Electronics & Gadgets')
        ->assertJsonPath('data.slug', 'electronics-gadgets')
        ->assertJsonPath('data.parent_id', null);

    $rootId = $rootResponse->json('data.id');

    // 2. Create child category
    $childResponse = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/admin/categories/add-category', [
            'name' => 'Smartphones & Accessories',
            'parent_id' => $rootId,
            'icon' => 'Phone',
            'order_index' => 1,
            'is_active' => true,
        ]);

    $childResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Smartphones & Accessories')
        ->assertJsonPath('data.parent_id', $rootId)
        ->assertJsonPath('data.parent.name', 'Electronics & Gadgets');
});

test('admin can retrieve categories in tree structure and parent lookups', function () {
    $root = Category::create([
        'name' => 'Fashion & Wear',
        'slug' => 'fashion-wear',
        'is_active' => true,
    ]);

    $child = Category::create([
        'name' => 'Traditional Nakshi Kantha',
        'slug' => 'traditional-nakshi-kantha',
        'parent_id' => $root->id,
        'is_active' => true,
    ]);

    // Tree list
    $listResponse = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/categories/get-categories');

    $listResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $data = collect($listResponse->json('data'));
    $foundRoot = $data->firstWhere('id', $root->id);
    expect($foundRoot)->not->toBeNull();
    expect($foundRoot['children'])->toHaveCount(1);
    expect($foundRoot['children'][0]['id'])->toBe($child->id);

    // Parents lookup
    $parentsResponse = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/categories/get-parents');

    $parentsResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $parentIds = collect($parentsResponse->json('data'))->pluck('id')->all();
    expect($parentIds)->toContain($root->id);
    expect($parentIds)->not->toContain($child->id);
});

test('admin can update a category', function () {
    $cat = Category::create([
        'name' => 'Home Decor',
        'slug' => 'home-decor',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/categories/update-category/{$cat->id}", [
            'name' => 'Modern Home Decor',
            'is_active' => false,
            'description' => 'Updated description for home decor',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Modern Home Decor')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.description', 'Updated description for home decor');
});

test('admin cannot create circular category dependency', function () {
    $parent = Category::create(['name' => 'Parent Cat', 'slug' => 'parent-cat']);
    $child = Category::create(['name' => 'Child Cat', 'slug' => 'child-cat', 'parent_id' => $parent->id]);

    // Attempt to set parent's parent to child
    $response = $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/categories/update-category/{$parent->id}", [
            'parent_id' => $child->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

test('safe delete prevents deleting category with subcategories or products', function () {
    $parent = Category::create(['name' => 'Main Cat', 'slug' => 'main-cat']);
    $child = Category::create(['name' => 'Sub Cat', 'slug' => 'sub-cat', 'parent_id' => $parent->id]);

    // 1. Try to delete parent with child
    $delParentResponse = $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/admin/categories/delete-category/{$parent->id}");

    $delParentResponse->assertStatus(422)
        ->assertJsonPath('success', false);

    // 2. Add product to child and try to delete child
    $seller = Seller::create([
        'user_id' => $this->admin->id,
        'store_name' => 'Test Store',
        'slug' => 'test-store',
        'contact_phone' => '+8801700000000',
        'status' => 'active',
        'district' => 'Jashore',
        'upazila' => 'Kotwali',
    ]);

    Product::create([
        'seller_id' => $seller->id,
        'category_id' => $child->id,
        'title' => 'Test Sub Product',
        'slug' => 'test-sub-product',
        'base_price' => 100,
        'stock_quantity' => 10,
        'status' => 'published',
    ]);

    $delChildResponse = $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/admin/categories/delete-category/{$child->id}");

    $delChildResponse->assertStatus(422)
        ->assertJsonPath('success', false);

    // 3. Delete a clean leaf category
    $cleanCat = Category::create(['name' => 'Clean Leaf', 'slug' => 'clean-leaf']);
    $delCleanResponse = $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/admin/categories/delete-category/{$cleanCat->id}");

    $delCleanResponse->assertStatus(200)
        ->assertJsonPath('success', true);
});
