<?php

use App\Models\Category;
use App\Models\Friendship;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserPick;

beforeEach(function () {
    $this->sellerUser = User::factory()->create(['status' => 'active']);
    $this->seller = Seller::create([
        'user_id' => $this->sellerUser->id,
        'store_name' => 'Jashore Gadget Zone',
        'slug' => 'jashore-gadget-zone',
        'contact_phone' => '+8801700112233',
        'status' => 'active',
        'district' => 'Jashore',
        'upazila' => 'Kotwali',
    ]);

    $this->category = Category::create([
        'name' => 'Consumer Electronics',
        'slug' => 'consumer-electronics',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'title' => 'Wireless Noise Canceling Headphones',
        'slug' => 'wireless-noise-canceling-headphones',
        'base_price' => 2500,
        'stock_quantity' => 50,
        'status' => 'published',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $this->product2 = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'title' => 'Smart Fitness Band',
        'slug' => 'smart-fitness-band',
        'base_price' => 1200,
        'stock_quantity' => 30,
        'status' => 'published',
        'visibility' => 'public',
        'is_active' => true,
    ]);
});

test('multiple users can pick the same catalog product without duplicating products', function () {
    $user1 = User::factory()->create(['status' => 'active']);
    $user2 = User::factory()->create(['status' => 'active']);
    $user3 = User::factory()->create(['status' => 'active']);

    $res1 = $this->actingAs($user1, 'sanctum')->postJson("/api/products/{$this->product->id}/pick", [
        'caption' => 'Love this sound quality!',
    ]);
    $res1->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('has_picked', true);

    $res2 = $this->actingAs($user2, 'sanctum')->postJson("/api/products/{$this->product->id}/pick", [
        'caption' => 'Best value for students.',
    ]);
    $res2->assertStatus(201)
        ->assertJsonPath('success', true);

    $res3 = $this->actingAs($user3, 'sanctum')->postJson("/api/products/{$this->product->id}/pick");
    $res3->assertStatus(201)
        ->assertJsonPath('success', true);

    // Only 1 canonical product exists
    $this->assertEquals(1, Product::where('slug', 'wireless-noise-canceling-headphones')->count());

    // 3 distinct picks exist
    $this->assertEquals(3, UserPick::where('product_id', $this->product->id)->count());
});

test('a user cannot pick the same product more than once', function () {
    $user = User::factory()->create(['status' => 'active']);

    $res1 = $this->actingAs($user, 'sanctum')->postJson("/api/products/{$this->product->id}/pick", [
        'caption' => 'First pick',
    ]);
    $res1->assertStatus(201);

    // Second attempt should fail with validation error
    $res2 = $this->actingAs($user, 'sanctum')->postJson("/api/products/{$this->product->id}/pick", [
        'caption' => 'Second pick',
    ]);
    $res2->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

test('one user can pick multiple distinct products', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user, 'sanctum')->postJson("/api/products/{$this->product->id}/pick")->assertStatus(201);
    $this->actingAs($user, 'sanctum')->postJson("/api/products/{$this->product2->id}/pick")->assertStatus(201);

    $this->assertEquals(2, UserPick::where('user_id', $user->id)->count());
});

test('user can remove a product from their picks', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user, 'sanctum')->postJson("/api/products/{$this->product->id}/pick")->assertStatus(201);
    $this->assertDatabaseHas('user_picks', [
        'user_id' => $user->id,
        'product_id' => $this->product->id,
    ]);

    $delRes = $this->actingAs($user, 'sanctum')->deleteJson("/api/products/{$this->product->id}/pick");
    $delRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('has_picked', false);

    $this->assertDatabaseMissing('user_picks', [
        'user_id' => $user->id,
        'product_id' => $this->product->id,
    ]);
});

test('authenticated user can list their own picked products via me/picked-products', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->actingAs($user, 'sanctum')->postJson("/api/products/{$this->product->id}/pick", [
        'caption' => 'My personal pick',
    ])->assertStatus(201);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/me/picked-products');
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_id', $this->product->id)
        ->assertJsonPath('data.0.caption', 'My personal pick');
});

test('can list another users publicly visible picked products', function () {
    $owner = User::factory()->create(['status' => 'active', 'username' => 'curator_fahim']);
    $owner->profile()->create(['locality' => 'Jashore Sadar']);

    // Public pick
    UserPick::create([
        'user_id' => $owner->id,
        'product_id' => $this->product->id,
        'caption' => 'Public recommendation',
        'is_public' => true,
        'recommendation_code' => 'REC-FAHIM-1',
    ]);

    // Private pick (should not be returned publicly)
    UserPick::create([
        'user_id' => $owner->id,
        'product_id' => $this->product2->id,
        'caption' => 'Secret wishlist',
        'is_public' => false,
        'recommendation_code' => 'REC-FAHIM-2',
    ]);

    $response = $this->getJson("/api/users/{$owner->username}/picked-products");
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.picks')
        ->assertJsonPath('data.picks.0.product_id', $this->product->id);
});

test('returns only qualifying confirmed friends who picked the product', function () {
    $currentUser = User::factory()->create(['status' => 'active', 'username' => 'viewer_user']);
    $currentUser->profile()->create(['locality' => 'Jashore Sadar']);

    // Friend 1: Confirmed friend (accepted)
    $friend1 = User::factory()->create(['status' => 'active', 'name' => 'Fahim Rahman', 'username' => 'fahim']);
    $friend1->profile()->create(['locality' => 'Keshabpur', 'bio' => 'Audio enthusiast']);
    Friendship::create([
        'sender_id' => $currentUser->id,
        'recipient_id' => $friend1->id,
        'status' => 'accepted',
    ]);
    UserPick::create([
        'user_id' => $friend1->id,
        'product_id' => $this->product->id,
        'caption' => 'Studio quality bass!',
        'is_public' => true,
        'recommendation_code' => 'REC-FAHIM-101',
    ]);

    // Friend 2: Confirmed friend (received & accepted)
    $friend2 = User::factory()->create(['status' => 'active', 'name' => 'Rahim Ali', 'username' => 'rahim']);
    $friend2->profile()->create(['locality' => 'Abhaynagar']);
    Friendship::create([
        'sender_id' => $friend2->id,
        'recipient_id' => $currentUser->id,
        'status' => 'accepted',
    ]);
    UserPick::create([
        'user_id' => $friend2->id,
        'product_id' => $this->product->id,
        'caption' => 'Super battery life.',
        'is_public' => true,
        'recommendation_code' => 'REC-RAHIM-102',
    ]);

    // Stranger: Picked the product, but NOT a friend
    $stranger = User::factory()->create(['status' => 'active', 'name' => 'Stranger User', 'username' => 'stranger']);
    UserPick::create([
        'user_id' => $stranger->id,
        'product_id' => $this->product->id,
        'caption' => 'I am a stranger.',
        'is_public' => true,
        'recommendation_code' => 'REC-STRANGER-103',
    ]);

    // Pending Friend: Sent request, but not accepted
    $pendingFriend = User::factory()->create(['status' => 'active', 'name' => 'Pending Friend', 'username' => 'pending']);
    Friendship::create([
        'sender_id' => $currentUser->id,
        'recipient_id' => $pendingFriend->id,
        'status' => 'pending',
    ]);
    UserPick::create([
        'user_id' => $pendingFriend->id,
        'product_id' => $this->product->id,
        'is_public' => true,
        'recommendation_code' => 'REC-PENDING-104',
    ]);

    // Blocked User: Picked the product, but blocked
    $blockedUser = User::factory()->create(['status' => 'active', 'name' => 'Blocked User', 'username' => 'blocked']);
    Friendship::create([
        'sender_id' => $currentUser->id,
        'recipient_id' => $blockedUser->id,
        'status' => 'blocked',
    ]);
    UserPick::create([
        'user_id' => $blockedUser->id,
        'product_id' => $this->product->id,
        'is_public' => true,
        'recommendation_code' => 'REC-BLOCKED-105',
    ]);

    // Friend with PRIVATE pick (is_public = false)
    $friendWithPrivatePick = User::factory()->create(['status' => 'active', 'name' => 'Private Friend', 'username' => 'private_friend']);
    Friendship::create([
        'sender_id' => $currentUser->id,
        'recipient_id' => $friendWithPrivatePick->id,
        'status' => 'accepted',
    ]);
    UserPick::create([
        'user_id' => $friendWithPrivatePick->id,
        'product_id' => $this->product->id,
        'is_public' => false,
        'recommendation_code' => 'REC-PRIVATE-106',
    ]);

    // Query friends who picked endpoint
    $response = $this->actingAs($currentUser, 'sanctum')
        ->getJson("/api/products/{$this->product->id}/picked-by-friends");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('count', 2)
        ->assertJsonCount(2, 'data');

    $usernames = collect($response->json('data'))->pluck('username')->all();
    $this->assertContains('fahim', $usernames);
    $this->assertContains('rahim', $usernames);
    $this->assertNotContains('stranger', $usernames);
    $this->assertNotContains('pending', $usernames);
    $this->assertNotContains('blocked', $usernames);
    $this->assertNotContains('private_friend', $usernames);

    // Verify public profile identifiers and referral attribution code
    $fahimData = collect($response->json('data'))->firstWhere('username', 'fahim');
    $this->assertEquals('Fahim Rahman', $fahimData['name']);
    $this->assertEquals('/users/fahim', $fahimData['profile_url']);
    $this->assertEquals('REC-FAHIM-101', $fahimData['recommendation_code']);
    $this->assertEquals('friends', $fahimData['relationship_status']);
});

test('guest visiting picked-by-friends receives count 0 and empty array without error', function () {
    $user = User::factory()->create(['status' => 'active']);
    UserPick::create([
        'user_id' => $user->id,
        'product_id' => $this->product->id,
        'is_public' => true,
        'recommendation_code' => 'REC-TEST-99',
    ]);

    $response = $this->getJson("/api/products/{$this->product->id}/picked-by-friends");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('count', 0)
        ->assertJsonCount(0, 'data');
});

test('product details response is enriched with friends who picked and current user pick status', function () {
    $currentUser = User::factory()->create(['status' => 'active']);
    $friend = User::factory()->create(['status' => 'active', 'name' => 'Fahim', 'username' => 'fahim']);
    $friend->profile()->create(['locality' => 'Jashore']);

    Friendship::create([
        'sender_id' => $currentUser->id,
        'recipient_id' => $friend->id,
        'status' => 'accepted',
    ]);

    UserPick::create([
        'user_id' => $friend->id,
        'product_id' => $this->product->id,
        'caption' => 'Must buy!',
        'is_public' => true,
        'recommendation_code' => 'REC-FAHIM-01',
    ]);

    // Current user has NOT picked yet
    $resBefore = $this->actingAs($currentUser, 'sanctum')
        ->getJson("/api/products/{$this->product->slug}");

    $resBefore->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.picked_by_friends.count', 1)
        ->assertJsonPath('data.picked_by_friends.users.0.username', 'fahim')
        ->assertJsonPath('data.current_user.has_picked', false);

    // Current user picks the product
    $this->actingAs($currentUser, 'sanctum')->postJson("/api/products/{$this->product->id}/pick");

    // Current user HAS picked now
    $resAfter = $this->actingAs($currentUser, 'sanctum')
        ->getJson("/api/products/{$this->product->slug}");

    $resAfter->assertStatus(200)
        ->assertJsonPath('data.current_user.has_picked', true);
});

test('explore catalog list enriches each product with friends who picked and user pick state', function () {
    $currentUser = User::factory()->create(['status' => 'active']);
    $friend = User::factory()->create(['status' => 'active', 'name' => 'Sadia', 'username' => 'sadia']);
    $friend->profile()->create(['locality' => 'Jashore']);

    Friendship::create([
        'sender_id' => $currentUser->id,
        'recipient_id' => $friend->id,
        'status' => 'accepted',
    ]);

    // Friend picked product 1
    UserPick::create([
        'user_id' => $friend->id,
        'product_id' => $this->product->id,
        'is_public' => true,
        'recommendation_code' => 'REC-SADIA-01',
    ]);

    // Current user picked product 2
    UserPick::create([
        'user_id' => $currentUser->id,
        'product_id' => $this->product2->id,
        'is_public' => true,
        'recommendation_code' => 'REC-CURRENT-02',
    ]);

    $response = $this->actingAs($currentUser, 'sanctum')->getJson('/api/products');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $items = collect($response->json('data'));

    $prod1 = $items->firstWhere('id', $this->product->id);
    $this->assertEquals(1, $prod1['picked_by_friends']['count']);
    $this->assertEquals('sadia', $prod1['picked_by_friends']['users'][0]['username']);
    $this->assertFalse($prod1['current_user']['has_picked']);

    $prod2 = $items->firstWhere('id', $this->product2->id);
    $this->assertEquals(0, $prod2['picked_by_friends']['count']);
    $this->assertTrue($prod2['current_user']['has_picked']);
});

test('picking product works with slug in route parameter', function () {
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$this->product->slug}/pick", [
            'caption' => 'Picked via slug route',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('has_picked', true)
        ->assertJsonPath('data.product_id', $this->product->id);

    $this->assertDatabaseHas('user_picks', [
        'user_id' => $user->id,
        'product_id' => $this->product->id,
        'caption' => 'Picked via slug route',
    ]);
});
