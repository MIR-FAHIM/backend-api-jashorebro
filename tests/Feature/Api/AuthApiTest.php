<?php

use App\Models\User;

test('a user can register with mobile number and receive token', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Fahim Ahmed',
        'username' => 'fahim_vibes',
        'phone' => '01712345678',
        'password' => 'secret123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => ['id', 'name', 'username', 'phone', 'profile'],
                'token',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'username' => 'fahim_vibes',
        'phone' => '+8801712345678',
    ]);

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $response->json('data.user.id'),
    ]);
});

test('a user can login with mobile number and password', function () {
    $user = User::factory()->create([
        'phone' => '+8801712345678',
        'password' => bcrypt('secret123'),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'phone' => '01712345678',
        'password' => 'secret123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'user' => ['id', 'phone'],
                'token',
            ],
        ]);
});

test('user cannot register with already registered phone number', function () {
    User::factory()->create([
        'phone' => '+8801712345678',
    ]);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Another Fahim',
        'username' => 'fahim_two',
        'phone' => '01712345678',
        'password' => 'secret123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['normalized_phone']);
});

test('a user can request and verify phone OTP', function () {
    $sendResponse = $this->postJson('/api/auth/otp/send', [
        'phone' => '01712345678',
        'purpose' => 'phone_verification',
    ]);

    $sendResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $verifyResponse = $this->postJson('/api/auth/otp/verify', [
        'phone' => '01712345678',
        'code' => '123456',
        'purpose' => 'phone_verification',
    ]);

    $verifyResponse->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('authenticated user can fetch their profile via me endpoint', function () {
    $user = User::factory()->create([
        'phone' => '+8801799999999',
    ]);
    $user->profile()->create(['bio' => 'Tester bio']);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me');

    $response->assertStatus(200)
        ->assertJsonPath('data.user.phone', '+8801799999999')
        ->assertJsonPath('data.user.profile.bio', 'Tester bio');
});
