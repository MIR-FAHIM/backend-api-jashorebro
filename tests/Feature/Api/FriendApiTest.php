<?php

use App\Models\Friendship;
use App\Models\User;

test('a user can send a friend request to another user', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();

    $response = $this->actingAs($sender, 'sanctum')->postJson('/api/friends/request', [
        'recipient_id' => $recipient->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('relationship_status', 'pending_sent');

    $this->assertDatabaseHas('friendships', [
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'status' => 'pending',
    ]);
});

test('a user cannot send a friend request to themselves', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/friends/request', [
        'recipient_id' => $user->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('recipient can accept an incoming friend request', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();

    $friendship = Friendship::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($recipient, 'sanctum')
        ->postJson("/api/friends/requests/{$friendship->id}/accept");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('relationship_status', 'friends');

    $this->assertDatabaseHas('friendships', [
        'id' => $friendship->id,
        'status' => 'accepted',
    ]);

    $this->assertTrue($recipient->fresh()->isFriendsWith($sender));
    $this->assertTrue($sender->fresh()->isFriendsWith($recipient));
});

test('recipient can decline an incoming friend request', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();

    $friendship = Friendship::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($recipient, 'sanctum')
        ->postJson("/api/friends/requests/{$friendship->id}/decline");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('relationship_status', 'none');

    $this->assertDatabaseMissing('friendships', [
        'id' => $friendship->id,
    ]);
});

test('sender can cancel an outgoing friend request', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();

    $friendship = Friendship::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($sender, 'sanctum')
        ->deleteJson("/api/friends/requests/{$friendship->id}/cancel");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('friendships', [
        'id' => $friendship->id,
    ]);
});

test('users can list confirmed friends and unfriend', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Friendship::create([
        'sender_id' => $userA->id,
        'recipient_id' => $userB->id,
        'status' => 'accepted',
        'acted_at' => now(),
    ]);

    // List friends
    $listResponse = $this->actingAs($userA, 'sanctum')->getJson('/api/friends');
    $listResponse->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $userB->id);

    // Unfriend
    $unfriendResponse = $this->actingAs($userA, 'sanctum')->deleteJson("/api/friends/{$userB->id}/unfriend");
    $unfriendResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('relationship_status', 'none');

    $this->assertFalse($userA->fresh()->isFriendsWith($userB));
});

test('can retrieve public instagram-style user profile with friendship status', function () {
    $viewer = User::factory()->create();
    $target = User::factory()->create(['username' => 'nabila_test']);
    $target->profile()->create(['bio' => 'Curator in Jashore', 'locality' => 'Jashore']);

    // Not friends yet
    $guestResponse = $this->getJson('/api/users/nabila_test');
    $guestResponse->assertStatus(200)
        ->assertJsonPath('data.username', 'nabila_test')
        ->assertJsonPath('data.profile.locality', 'Jashore')
        ->assertJsonPath('data.relationship_status', 'none');

    // Authenticated viewer who has sent a request
    Friendship::create([
        'sender_id' => $viewer->id,
        'recipient_id' => $target->id,
        'status' => 'pending',
    ]);

    $authResponse = $this->actingAs($viewer, 'sanctum')->getJson('/api/users/nabila_test');
    $authResponse->assertStatus(200)
        ->assertJsonPath('data.relationship_status', 'pending_sent');
});
