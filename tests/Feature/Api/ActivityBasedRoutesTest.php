<?php

use App\Models\Role;
use App\Models\User;

test('activity-based auth routes work as expected', function () {
    // 1. Register user
    $regResponse = $this->postJson('/api/auth/register-user', [
        'name' => 'Activity Test User',
        'username' => 'activity_user',
        'phone' => '01799112233',
        'password' => 'secret123',
    ]);
    $regResponse->assertStatus(201)
        ->assertJsonPath('success', true);

    // 2. Login user
    $loginResponse = $this->postJson('/api/auth/login-user', [
        'phone' => '01799112233',
        'password' => 'secret123',
    ]);
    $loginResponse->assertStatus(200)
        ->assertJsonPath('success', true);
    $token = $loginResponse->json('data.token');

    // 3. Send and verify OTP
    $this->postJson('/api/auth/send-otp', [
        'phone' => '01799112233',
        'purpose' => 'phone_verification',
    ])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->postJson('/api/auth/verify-otp', [
        'phone' => '01799112233',
        'code' => '123456',
        'purpose' => 'phone_verification',
    ])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // 4. Get current user
    $this->withToken($token)->getJson('/api/auth/get-current-user')
        ->assertStatus(200)
        ->assertJsonPath('data.user.username', 'activity_user');

    // 5. Logout user
    $this->withToken($token)->postJson('/api/auth/logout-user')
        ->assertStatus(200);
});

test('activity-based consumer routes respond appropriately', function () {
    $user = User::factory()->create([
        'phone' => '+8801788776655',
        'status' => 'active',
    ]);
    $user->profile()->create(['locality' => 'Jashore']);

    // Profile summary
    $this->actingAs($user, 'sanctum')->getJson('/api/profile/get-profile-summary')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Friends list
    $this->actingAs($user, 'sanctum')->getJson('/api/friends/get-friends')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Addresses list
    $this->actingAs($user, 'sanctum')->getJson('/api/addresses/get-addresses')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Notifications list
    $this->actingAs($user, 'sanctum')->getJson('/api/notifications/get-notifications')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // My Picks list
    $this->actingAs($user, 'sanctum')->getJson('/api/picks/get-my-picks')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // My Shop
    $this->actingAs($user, 'sanctum')->getJson('/api/shops/get-my-shop')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Drops public feed
    $this->getJson('/api/drops/get-drops')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Public feed
    $this->getJson('/api/feed/get-feed')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Earnings summary
    $this->actingAs($user, 'sanctum')->getJson('/api/earnings/get-earnings-summary')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Leaderboards
    $this->getJson('/api/leaderboards/get-leaderboards')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('activity-based admin routes enforce admin role and respond to authorized admins', function () {
    $regularUser = User::factory()->create(['status' => 'active']);
    $adminUser = User::factory()->create(['status' => 'active']);

    $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
    $adminUser->roles()->sync([$adminRole->id]);

    // Unauthorized regular user blocked with 403
    $this->actingAs($regularUser, 'sanctum')->getJson('/api/admin/overview/get-overview')
        ->assertStatus(403);
    $this->actingAs($regularUser, 'sanctum')->getJson('/api/admin/users/get-users')
        ->assertStatus(403);

    // Authorized admin permitted with 200
    $this->actingAs($adminUser, 'sanctum')->getJson('/api/admin/overview/get-overview')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->actingAs($adminUser, 'sanctum')->getJson('/api/admin/users/get-users')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->actingAs($adminUser, 'sanctum')->getJson('/api/admin/products/get-products')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->actingAs($adminUser, 'sanctum')->getJson('/api/admin/pos/search-products')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});
