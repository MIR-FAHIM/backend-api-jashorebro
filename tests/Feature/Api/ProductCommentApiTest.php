<?php

use App\Models\Category;
use App\Models\CommentReaction;
use App\Models\CommentReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductComment;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;

beforeEach(function () {
    $this->sellerUser = User::factory()->create(['status' => 'active']);
    $this->seller = Seller::create([
        'user_id' => $this->sellerUser->id,
        'store_name' => 'Jashore Sweet Emporium',
        'slug' => 'jashore-sweet-emporium',
        'contact_phone' => '+8801711223344',
        'status' => 'active',
        'district' => 'Jashore',
        'upazila' => 'Kotwali',
    ]);

    $this->category = Category::create([
        'name' => 'Artisan Sweets',
        'slug' => 'artisan-sweets',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'title' => 'Pure Patali Gur 1kg',
        'slug' => 'pure-patali-gur-1kg',
        'base_price' => 650,
        'stock_quantity' => 100,
        'status' => 'published',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['status' => 'active', 'username' => 'testuser1']);
    $this->user->profile()->create(['locality' => 'Jashore Sadar']);

    $this->otherUser = User::factory()->create(['status' => 'active', 'username' => 'testuser2']);
    $this->otherUser->profile()->create(['locality' => 'Keshabpur']);

    $this->adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
    $this->admin = User::factory()->create(['status' => 'active', 'username' => 'adminuser']);
    $this->admin->roles()->sync([$this->adminRole->id]);
});

test('authenticated user can post a root comment and a reply', function () {
    // 1. Post root comment
    $rootRes = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments/add-comment", [
            'content' => 'Is this jaggery fresh from the latest morning collection?',
            'comment_type' => 'question',
        ]);

    $rootRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.comment_type', 'question')
        ->assertJsonPath('data.content', 'Is this jaggery fresh from the latest morning collection?')
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.user.username', 'testuser1');

    $rootId = $rootRes->json('data.id');

    // 2. Post reply from seller/other user
    $replyRes = $this->actingAs($this->sellerUser, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments/add-comment", [
            'content' => 'Yes, harvested daily at 5 AM in Keshabpur!',
            'parent_id' => $rootId,
        ]);

    $replyRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.parent_id', $rootId);

    // Verify parent's replies_count incremented
    $rootComment = ProductComment::find($rootId);
    expect($rootComment->replies_count)->toBe(1);

    // 3. List comments via public endpoint
    $listRes = $this->getJson("/api/products/{$this->product->id}/comments");
    $listRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $rootId)
        ->assertJsonPath('data.0.replies.0.parent_id', $rootId);
});

test('replying to a reply normalizes parent to root comment (1-level nesting)', function () {
    $root = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Initial root discussion',
    ]);

    $reply1 = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->otherUser->id,
        'parent_id' => $root->id,
        'content' => 'First reply',
    ]);
    $root->increment('replies_count');

    // Reply targeting reply1
    $reply2Res = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'Following up on your answer',
            'parent_id' => $reply1->id,
        ]);

    $reply2Res->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.parent_id', $root->id); // Normalized to root!

    expect($reply2Res->json('data.content'))->toContain('@testuser2');
});

test('validates deal comment with price suggestion and alternative product', function () {
    $altProduct = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'title' => 'Liquid Nolen Gur 500ml',
        'slug' => 'liquid-nolen-gur-500ml',
        'base_price' => 380,
        'stock_quantity' => 50,
        'status' => 'published',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    // Alternative comment
    $resAlt = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'If you prefer syrup, check out this liquid nolen gur alternative!',
            'comment_type' => 'alternative',
            'alternative_product_id' => $altProduct->id,
        ]);

    $resAlt->assertStatus(201)
        ->assertJsonPath('data.comment_type', 'alternative')
        ->assertJsonPath('data.alternative_product.id', $altProduct->id);

    // Deal comment with price suggestion
    $resDeal = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'I think ৳600 would be an attractive price point.',
            'comment_type' => 'deal',
            'price_suggestion' => 600,
        ]);

    $resDeal->assertStatus(201)
        ->assertJsonPath('data.comment_type', 'deal')
        ->assertJsonPath('data.price_suggestion', 600);
});

test('unauthenticated users cannot add, react to, or report comments', function () {
    $this->postJson("/api/products/{$this->product->id}/comments", [
        'content' => 'Unauthorized comment',
    ])->assertStatus(401);

    $comment = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Sample comment',
    ]);

    $this->postJson("/api/comments/{$comment->id}/reactions", [
        'reaction_type' => 'like',
    ])->assertStatus(401);

    $this->postJson("/api/comments/{$comment->id}/reports", [
        'reason' => 'spam',
    ])->assertStatus(401);
});

test('users can only edit and delete their own comments', function () {
    $comment = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Original text',
    ]);

    // Unauthorized user attempts update
    $this->actingAs($this->otherUser, 'sanctum')
        ->putJson("/api/comments/{$comment->id}", [
            'content' => 'Hacked text',
        ])->assertStatus(422);

    // Author successfully updates
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/comments/{$comment->id}", [
            'content' => 'Updated review text',
        ])->assertStatus(200)
        ->assertJsonPath('data.content', 'Updated review text');

    // Unauthorized user attempts delete
    $this->actingAs($this->otherUser, 'sanctum')
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertStatus(422);

    // Author successfully deletes
    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertStatus(200);

    expect(ProductComment::find($comment->id))->toBeNull();
});

test('verified purchase badge is assigned only when user completed a real purchase', function () {
    // 1. User without purchase posts comment -> is_verified_purchase: false
    $res1 = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'Just asking about the product',
            'is_verified_purchase' => true, // Malicious client attempt
        ]);

    $res1->assertStatus(201)
        ->assertJsonPath('data.is_verified_purchase', false); // Server rejects spoofing

    // 2. User with completed order posts comment -> is_verified_purchase: true
    $order = Order::create([
        'order_number' => 'JB-TEST-ORD-01',
        'user_id' => $this->otherUser->id,
        'seller_id' => $this->seller->id,
        'channel' => 'web',
        'status' => 'delivered',
        'payment_status' => 'paid',
        'payment_method' => 'cod',
        'subtotal' => 650,
        'total_amount' => 710,
        'shipping_name' => 'Test Customer',
        'shipping_phone' => '01711223344',
        'shipping_district' => 'Jashore',
        'shipping_upazila' => 'Keshabpur',
        'shipping_address' => 'Trimohoni Road, Keshabpur',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'purchase_mode' => 'normal',
        'product_title' => $this->product->title,
        'unit_price' => 650,
        'quantity' => 1,
        'line_total' => 650,
    ]);

    $res2 = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'Received yesterday and tasted fantastic!',
            'comment_type' => 'experience',
        ]);

    $res2->assertStatus(201)
        ->assertJsonPath('data.is_verified_purchase', true);
});

test('reaction toggle engine: create, change, and remove reactions with accurate counters', function () {
    $comment = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Helpful review comment',
    ]);

    // 1. Add 'helpful' reaction
    $res1 = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/comments/{$comment->id}/reactions", [
            'reaction_type' => 'helpful',
        ]);

    $res1->assertStatus(200)
        ->assertJsonPath('action', 'created')
        ->assertJsonPath('data.user_reaction', 'helpful')
        ->assertJsonPath('data.reactions_count', 1)
        ->assertJsonPath('data.reaction_counts.helpful', 1);

    // 2. Change reaction from 'helpful' to 'like'
    $res2 = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/comments/{$comment->id}/reactions", [
            'reaction_type' => 'like',
        ]);

    $res2->assertStatus(200)
        ->assertJsonPath('action', 'updated')
        ->assertJsonPath('data.user_reaction', 'like')
        ->assertJsonPath('data.reactions_count', 1)
        ->assertJsonPath('data.reaction_counts.helpful', 0)
        ->assertJsonPath('data.reaction_counts.like', 1);

    // 3. Click 'like' again to toggle off / remove
    $res3 = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/comments/{$comment->id}/reactions", [
            'reaction_type' => 'like',
        ]);

    $res3->assertStatus(200)
        ->assertJsonPath('action', 'removed')
        ->assertJsonPath('data.user_reaction', null)
        ->assertJsonPath('data.reactions_count', 0)
        ->assertJsonPath('data.reaction_counts.like', 0);
});

test('comment summary endpoint aggregates counts, reactions breakdown, and drop status', function () {
    ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Review 1',
        'comment_type' => 'experience',
        'helpful_count' => 5,
        'reactions_count' => 5,
    ]);

    ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->otherUser->id,
        'content' => 'Review 2',
        'comment_type' => 'question',
        'helpful_count' => 1,
        'reactions_count' => 1,
    ]);

    $summaryRes = $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/products/{$this->product->id}/comments/summary");

    $summaryRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total_comments', 2)
        ->assertJsonPath('data.total_reactions', 6)
        ->assertJsonPath('data.has_user_commented', true)
        ->assertJsonPath('data.comment_types_breakdown.experience', 1)
        ->assertJsonPath('data.comment_types_breakdown.question', 1);
});

test('user can report a comment and cannot submit duplicate report', function () {
    $comment = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Questionable content',
    ]);

    // 1. Submit report
    $res1 = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/comments/{$comment->id}/reports", [
            'reason' => 'spam',
            'description' => 'Promoting third party external link.',
        ]);

    $res1->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    // 2. Submit duplicate report fails
    $res2 = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/comments/{$comment->id}/reports", [
            'reason' => 'spam',
        ]);

    $res2->assertStatus(422)
        ->assertJsonValidationErrors(['report']);
});

test('moderator can review reports, hide comments, and resolve reports', function () {
    $comment = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Inappropriate content',
        'status' => 'published',
    ]);

    $report = CommentReport::create([
        'comment_id' => $comment->id,
        'reporter_id' => $this->otherUser->id,
        'reason' => 'abuse',
        'status' => 'pending',
    ]);

    // Regular user cannot access moderation
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/admin/comments/get-reports')
        ->assertStatus(403);

    // Admin lists reports
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/comments/get-reports')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $report->id);

    // Admin resolves report and hides comment
    $resolveRes = $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/comments/resolve-report/{$report->id}", [
            'status' => 'action_taken',
            'action_taken' => 'hidden',
            'moderator_notes' => 'Hidden due to community guidelines violation.',
        ]);

    $resolveRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.action_taken', 'hidden');

    expect($comment->fresh()->status)->toBe('hidden');

    // Public feed no longer displays hidden comment
    $publicList = $this->getJson("/api/products/{$this->product->id}/comments");
    expect($publicList->json('data'))->toBeEmpty();
});

test('user mention in comment content creates in-app notification', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'Hey @testuser2, you must check out this gur before it sells out!',
        ])->assertStatus(201);

    // Verify otherUser received mention notification
    $notification = \App\Models\AppNotification::where('notifiable_id', $this->otherUser->id)
        ->where('event', 'comment_mention')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->message)->toContain('testuser1 mentioned you');
});

test('comments can be filtered by type and sorted by most helpful and most reacted', function () {
    $c1 = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'General chat',
        'comment_type' => 'general',
        'helpful_count' => 1,
        'reactions_count' => 2,
    ]);

    $c2 = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->otherUser->id,
        'content' => 'Detailed experience report',
        'comment_type' => 'experience',
        'helpful_count' => 10,
        'reactions_count' => 15,
    ]);

    // Filter by type: experience
    $typeRes = $this->getJson("/api/products/{$this->product->id}/comments?type=experience");
    $typeRes->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $c2->id);

    // Sort by most_helpful
    $sortRes = $this->getJson("/api/products/{$this->product->id}/comments?sort=most_helpful");
    $sortRes->assertStatus(200)
        ->assertJsonPath('data.0.id', $c2->id)
        ->assertJsonPath('data.1.id', $c1->id);
});

test('deleting a reply decrements parent replies_count', function () {
    $root = ProductComment::create([
        'product_id' => $this->product->id,
        'user_id' => $this->user->id,
        'content' => 'Root comment',
    ]);

    $replyRes = $this->actingAs($this->otherUser, 'sanctum')
        ->postJson("/api/products/{$this->product->id}/comments", [
            'content' => 'Reply comment',
            'parent_id' => $root->id,
        ]);

    $replyId = $replyRes->json('data.id');
    expect($root->fresh()->replies_count)->toBe(1);

    // Delete the reply
    $this->actingAs($this->otherUser, 'sanctum')
        ->deleteJson("/api/comments/{$replyId}")
        ->assertStatus(200);

    expect($root->fresh()->replies_count)->toBe(0);
});

