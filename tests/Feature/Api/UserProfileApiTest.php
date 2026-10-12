<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
});

test('authenticated user can upload avatar image', function () {
    $user = User::factory()->create([
        'username' => 'testuser_avatar',
    ]);
    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->create('my_avatar.jpg', 50, 'image/jpeg');

    $response = $this->postJson('/api/profile/upload-avatar', [
        'avatar' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Profile picture updated successfully.',
        ])
        ->assertJsonStructure([
            'data' => [
                'avatar_path',
                'avatar_url',
                'user' => ['id', 'username', 'avatar_url'],
            ],
        ]);

    $user->refresh();
    expect($user->profile)->not->toBeNull();
    expect($user->profile->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->profile->avatar_path);
});

test('authenticated user can upload cover image', function () {
    $user = User::factory()->create([
        'username' => 'testuser_cover',
    ]);
    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->create('my_cover.jpg', 150, 'image/jpeg');

    $response = $this->postJson('/api/profile/upload-cover', [
        'cover' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Cover banner updated successfully.',
        ])
        ->assertJsonStructure([
            'data' => [
                'cover_image_path',
                'cover_url',
                'user' => ['id', 'username', 'cover_url'],
            ],
        ]);

    $user->refresh();
    expect($user->profile)->not->toBeNull();
    expect($user->profile->cover_image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->profile->cover_image_path);
});

test('uploading a new avatar replaces and removes the old file', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $file1 = UploadedFile::fake()->create('first.jpg', 50, 'image/jpeg');
    $this->postJson('/api/profile/upload-avatar', ['avatar' => $file1]);
    $user->refresh();
    $oldPath = $user->profile->avatar_path;
    Storage::disk('public')->assertExists($oldPath);

    $file2 = UploadedFile::fake()->create('second.png', 50, 'image/png');
    $this->postJson('/api/profile/upload-avatar', ['avatar' => $file2]);
    $user->refresh();
    $newPath = $user->profile->avatar_path;

    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($newPath);
});

test('authenticated user can remove avatar and cover image', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $avatar = UploadedFile::fake()->create('avatar.jpg', 50, 'image/jpeg');
    $cover = UploadedFile::fake()->create('cover.jpg', 100, 'image/jpeg');

    $this->postJson('/api/profile/upload-avatar', ['avatar' => $avatar]);
    $this->postJson('/api/profile/upload-cover', ['cover' => $cover]);

    $user->refresh();
    $avatarPath = $user->profile->avatar_path;
    $coverPath = $user->profile->cover_image_path;

    // Delete avatar
    $resAvatar = $this->deleteJson('/api/profile/remove-avatar');
    $resAvatar->assertStatus(200);
    $user->refresh();
    expect($user->profile->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($avatarPath);

    // Delete cover
    $resCover = $this->deleteJson('/api/profile/remove-cover');
    $resCover->assertStatus(200);
    $user->refresh();
    expect($user->profile->cover_image_path)->toBeNull();
    Storage::disk('public')->assertMissing($coverPath);
});

test('authenticated user can update profile details with name, bio, locality, and website', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
    ]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/profile/update-profile', [
        'name' => 'Updated Jashore Bro',
        'bio' => 'Exploring authentic artisans and goods in Jashore.',
        'locality' => 'Jashore Sadar',
        'website_url' => 'https://jashorebro.com',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => [
                'user' => [
                    'name' => 'Updated Jashore Bro',
                    'profile' => [
                        'bio' => 'Exploring authentic artisans and goods in Jashore.',
                        'locality' => 'Jashore Sadar',
                        'website_url' => 'https://jashorebro.com',
                    ],
                ],
            ],
        ]);

    $user->refresh();
    expect($user->name)->toBe('Updated Jashore Bro');
    expect($user->profile->bio)->toBe('Exploring authentic artisans and goods in Jashore.');
    expect($user->profile->locality)->toBe('Jashore Sadar');
});

test('profile summary returns resolved avatar and cover URLs', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $avatar = UploadedFile::fake()->create('avatar.jpg', 50, 'image/jpeg');
    $cover = UploadedFile::fake()->create('cover.jpg', 100, 'image/jpeg');
    $resAvatar = $this->postJson('/api/profile/upload-avatar', ['avatar' => $avatar]);
    $resCover = $this->postJson('/api/profile/upload-cover', ['cover' => $cover]);

    $response = $this->getJson('/api/profile/get-profile-summary');

    $response->assertStatus(200);

    $avatarUrl = $response->json('data.user.avatar_url');
    $coverUrl = $response->json('data.user.cover_url');

    expect($avatarUrl)->toContain('/storage/avatars/');
    expect($coverUrl)->toContain('/storage/covers/');
});
