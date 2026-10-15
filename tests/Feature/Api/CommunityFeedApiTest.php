<?php

use App\Models\Category;
use App\Models\CommentReaction;
use App\Models\FeedFeedback;
use App\Models\FeedImpression;
use App\Models\Friendship;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use App\Models\ProductComment;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserPick;

beforeEach(function () {
    $this->sellerUser = User::factory()->create(['status' => 'active']);
    $this->seller = Seller::create([
        'user_id' => $this->sellerUser->id,
        'store_name' => 'Jashore Crafts & Foods',
        'slug' => 'jashore-crafts-foods',
        'contact_phone' => '+8801711000111',
        'status' => 'active',
        'district' => 'Jashore',
        'upazila' => 'Kotwali',
    ]);

    $this->category = Category::create([
        'name' => 'Handloom',
        'slug' => 'handloom',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'title' => 'Nakshi Kantha Traditional',
        'slug' => 'nakshi-kantha-traditional',
        'base_price' => 1200,
        'cost_price' => 800,
        'supplier_allocation_price' => 800,
        'is_group_drop_enabled' => true,
        'stock_quantity' => 50,
        'status' => 'published',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $this->userA = User::factory()->create(['status' => 'active', 'username' => 'user_a']);
    $this->userA->profile()->create(['locality' => 'Jashore Sadar']);

    $this->userB = User::factory()->create(['status' => 'active', 'username' => 'user_b']);
    $this->userB->profile()->create(['locality' => 'Jashore Sadar']);

    $this->userC = User::factory()->create(['status' => 'active', 'username' => 'user_c']);
    $this->userC->profile()->create(['locality' => 'Manirampur']);
});

test('public feed returns multi-domain content cards with truthful schema', function () {
    // 1. Recommendation by userA
    UserPick::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'caption' => 'The embroidery on this Nakshi Kantha is exquisite!',
        'is_public' => true,
        'recommendation_code' => 'REC-TEST-A1',
    ]);

    // 2. Discussion by userB
    ProductComment::create([
        'user_id' => $this->userB->id,
        'product_id' => $this->product->id,
        'content' => 'Does this come with authentic silk threading?',
        'comment_type' => ProductComment::TYPE_QUESTION,
        'status' => ProductComment::STATUS_PUBLISHED,
    ]);

    // 3. Drop campaign by userC
    $campaign = GroupBuyCampaign::create([
        'campaign_code' => 'DRP-TEST-1',
        'product_id' => $this->product->id,
        'title' => 'Nakshi Kantha Group Drop',
        'status' => 'active',
        'group_price' => 950,
        'target_participants' => 5,
        'start_at' => now()->subHour(),
        'end_at' => now()->addDays(2),
        'created_by' => $this->userC->id,
        'organizer_user_id' => $this->userC->id,
    ]);

    $response = $this->getJson('/api/feed/get-feed');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'tab' => 'for_you',
        ]);

    $data = $response->json('data');
    expect(count($data))->toBeGreaterThanOrEqual(3);

    $types = array_column($data, 'type');
    expect($types)->toContain('recommendation');
    expect($types)->toContain('discussion');
    expect($types)->toContain('drop');

    // Check standardized keys on a card
    $firstItem = $data[0];
    expect($firstItem)->toHaveKeys(['feed_item_id', 'type', 'source_id', 'published_at', 'reason', 'actor', 'allowed_actions']);
});

test('drop toggle interest sets interested status without unlocking campaign quota', function () {
    $campaign = GroupBuyCampaign::create([
        'campaign_code' => 'DRP-TEST-2',
        'product_id' => $this->product->id,
        'title' => 'Exclusive Kantha Drop',
        'status' => 'active',
        'group_price' => 900,
        'target_participants' => 2,
        'start_at' => now()->subHour(),
        'end_at' => now()->addDays(2),
        'created_by' => $this->userA->id,
        'organizer_user_id' => $this->userA->id,
    ]);

    // User B expresses interest
    $res = $this->actingAs($this->userB, 'sanctum')
        ->postJson("/api/drops/{$campaign->id}/toggle-interest");

    $res->assertStatus(200)
        ->assertJson([
            'success' => true,
            'is_joined' => true,
        ]);

    // Verify DB participant status is 'interested' and order_id is null
    $participant = GroupBuyParticipant::where('campaign_id', $campaign->id)
        ->where('user_id', $this->userB->id)
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->status)->toBe('interested');
    expect($participant->order_id)->toBeNull();

    // Verify campaign is NOT succeeded despite interest
    $campaign->refresh();
    expect($campaign->status)->toBe('active');

    // Formatted details check: qualifying_participants_count must be 0, interest_count must be 1
    $dropData = $res->json('data');
    expect($dropData['qualifying_participants_count'])->toBe(0);
    expect($dropData['interest_count'])->toBe(1);
    expect($dropData['progress_percent'])->toBe(0);
    expect($dropData['viewer_state']['has_expressed_interest'])->toBeTrue();
    expect($dropData['viewer_state']['has_order_reservation'])->toBeFalse();
});

test('format campaign is idempotent and does not mutate status on expired GET', function () {
    $expiredCampaign = GroupBuyCampaign::create([
        'campaign_code' => 'DRP-EXPIRED-1',
        'product_id' => $this->product->id,
        'title' => 'Past Campaign',
        'status' => 'active',
        'group_price' => 900,
        'target_participants' => 5,
        'start_at' => now()->subDays(5),
        'end_at' => now()->subDay(),
        'created_by' => $this->userA->id,
        'organizer_user_id' => $this->userA->id,
    ]);

    // GET request
    $res = $this->getJson("/api/drops/{$expiredCampaign->id}");
    $res->assertStatus(200);

    // Database row status should remain intact (no unsolicited GET mutation)
    $expiredCampaign->refresh();
    expect($expiredCampaign->status)->toBe('active');
});

test('feed respects following and confirmed friends filters', function () {
    // User A and User B are confirmed friends
    Friendship::create([
        'sender_id' => $this->userA->id,
        'recipient_id' => $this->userB->id,
        'status' => 'accepted',
    ]);

    // User A follows User C
    $this->userA->following()->create([
        'followed_user_id' => $this->userC->id,
    ]);

    // User B posts recommendation
    UserPick::create([
        'user_id' => $this->userB->id,
        'product_id' => $this->product->id,
        'caption' => 'Friend recommendation from B',
        'is_public' => true,
        'recommendation_code' => 'REC-B-1',
    ]);

    // User C posts discussion
    ProductComment::create([
        'user_id' => $this->userC->id,
        'product_id' => $this->product->id,
        'content' => 'Followed curator discussion from C',
        'comment_type' => ProductComment::TYPE_EXPERIENCE,
        'status' => ProductComment::STATUS_PUBLISHED,
    ]);

    // Acting as User A: Following tab should include User C's item
    $followingRes = $this->actingAs($this->userA, 'sanctum')
        ->getJson('/api/feed/get-following-feed');

    $followingRes->assertStatus(200);
    $followingData = $followingRes->json('data');
    $authorIds = array_column(array_column($followingData, 'actor'), 'id');
    expect($authorIds)->toContain($this->userC->id);

    // Acting as User A: Friends only filter should only include User B's item
    $friendsRes = $this->actingAs($this->userA, 'sanctum')
        ->getJson('/api/feed/get-feed?friends_only=1');

    $friendsRes->assertStatus(200);
    $friendsData = $friendsRes->json('data');
    $friendAuthorIds = array_column(array_column($friendsData, 'actor'), 'id');
    expect($friendAuthorIds)->toContain($this->userB->id);
    expect($friendAuthorIds)->not->toContain($this->userC->id);
});

test('user can hide feed items and mute authors', function () {
    $pick = UserPick::create([
        'user_id' => $this->userB->id,
        'product_id' => $this->product->id,
        'caption' => 'Item to hide',
        'is_public' => true,
        'recommendation_code' => 'REC-HIDE-1',
    ]);

    $feedItemId = "rec_{$pick->id}";

    // Hide item
    $hideRes = $this->actingAs($this->userA, 'sanctum')
        ->postJson('/api/feed/add-feedback', [
            'feed_item_id' => $feedItemId,
            'content_type' => 'recommendation',
            'source_id' => $pick->id,
            'action' => 'hide',
            'reason' => 'not_interested',
        ]);

    $hideRes->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(FeedFeedback::where('user_id', $this->userA->id)->where('action', 'hide')->exists())->toBeTrue();

    // Verify item is excluded from User A's feed
    $feedRes = $this->actingAs($this->userA, 'sanctum')
        ->getJson('/api/feed/get-feed');

    $feedRes->assertStatus(200);
    $feedItemIds = array_column($feedRes->json('data'), 'feed_item_id');
    expect($feedItemIds)->not->toContain($feedItemId);
});

test('feed telemetry impressions can be batch logged', function () {
    $impressionsPayload = [
        'session_id' => 'sess_test_123',
        'impressions' => [
            [
                'feed_item_id' => 'rec_1',
                'content_type' => 'recommendation',
                'source_id' => 1,
                'tab' => 'for_you',
                'position' => 0,
            ],
            [
                'feed_item_id' => 'drop_2',
                'content_type' => 'drop',
                'source_id' => 2,
                'tab' => 'for_you',
                'position' => 1,
            ],
        ],
    ];

    $res = $this->actingAs($this->userA, 'sanctum')
        ->postJson('/api/feed/add-impressions', $impressionsPayload);

    $res->assertStatus(200)
        ->assertJson([
            'success' => true,
            'count' => 2,
        ]);

    expect(FeedImpression::where('session_id', 'sess_test_123')->count())->toBe(2);
});
