<?php

use App\Http\Controllers\Api\AdminAttributeController;
use App\Http\Controllers\Api\AdminCommunityController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminGroupBuyController;
use App\Http\Controllers\Api\AdminLogController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPosController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommunityDropController;
use App\Http\Controllers\Api\CommunityFeedController;
use App\Http\Controllers\Api\CommunityShopController;
use App\Http\Controllers\Api\EarningsController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\UserFollowController;
use App\Http\Controllers\Api\UserPickController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JashoreBro Activity-Based API Routes
|--------------------------------------------------------------------------
| Standardized format:
|   /api/{module}/{action}
|   /api/{module}/{action}/{identifier}
|   /api/admin/{module}/{action}
|   /api/admin/{module}/{action}/{identifier}
|
| HTTP verbs adhere to:
|   GET: read operations (get-*, find-*, search-*, lookup-*)
|   POST: creation and state transition commands (add-*, register-*, login-*, cancel-*, etc.)
|   PATCH/PUT: idempotent resource edits and updates (update-*, mark-*-read, set-default-*)
|   DELETE: deletions and unlinks (delete-*, unfriend-*, unfollow-*, cancel-*)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Authentication Module (/api/auth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // Activity-based routes
    Route::post('/register-user', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login-user', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])->name('auth.send-otp');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('auth.verify-otp');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/get-current-user', [AuthController::class, 'me'])->name('auth.current-user');
        Route::post('/logout-user', [AuthController::class, 'logout'])->name('auth.logout');
    });

    // Compatibility aliases
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/otp/send', [AuthController::class, 'sendOtp']);
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Current authenticated user profile
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user()->load('profile', 'roles');
});

/*
|--------------------------------------------------------------------------
| User Profile & Community Discovery (/api/profile, /api/users)
|--------------------------------------------------------------------------
*/
Route::prefix('profile')->middleware('auth:sanctum')->group(function () {
    Route::get('/get-profile-summary', [FriendController::class, 'summary'])->name('profile.summary');
    // Compatibility alias
    Route::get('/summary', [FriendController::class, 'summary']);
});

Route::prefix('users')->group(function () {
    Route::get('/get-user-profile/{username}', [FriendController::class, 'userProfile'])->name('users.profile');
    // Compatibility alias
    Route::get('/{username}', [FriendController::class, 'userProfile']);
});

/*
|--------------------------------------------------------------------------
| Friends & Social Graph Routes (/api/friends)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('friends')->group(function () {
    // Activity-based routes
    Route::get('/get-friends', [FriendController::class, 'index'])->name('friends.list');
    Route::get('/get-friend-requests', [FriendController::class, 'requests'])->name('friends.requests');
    Route::post('/send-friend-request', [FriendController::class, 'sendRequest'])->name('friends.send-request');
    Route::post('/accept-friend-request/{id}', [FriendController::class, 'acceptRequest'])->name('friends.accept');
    Route::post('/decline-friend-request/{id}', [FriendController::class, 'declineRequest'])->name('friends.decline');
    Route::delete('/cancel-friend-request/{id}', [FriendController::class, 'cancelRequest'])->name('friends.cancel');
    Route::delete('/unfriend-user/{user_id}', [FriendController::class, 'unfriend'])->name('friends.unfriend');
    Route::get('/find-friends', [FriendController::class, 'find'])->name('friends.find');
    Route::get('/get-friend-suggestions', [FriendController::class, 'suggestions'])->name('friends.suggestions');

    // Compatibility aliases
    Route::get('/', [FriendController::class, 'index']);
    Route::get('/requests', [FriendController::class, 'requests']);
    Route::post('/request', [FriendController::class, 'sendRequest']);
    Route::post('/requests/{id}/accept', [FriendController::class, 'acceptRequest']);
    Route::post('/requests/{id}/decline', [FriendController::class, 'declineRequest']);
    Route::delete('/requests/{id}/cancel', [FriendController::class, 'cancelRequest']);
    Route::delete('/{user_id}/unfriend', [FriendController::class, 'unfriend']);
    Route::get('/find', [FriendController::class, 'find']);
    Route::get('/suggestions', [FriendController::class, 'suggestions']);
});

/*
|--------------------------------------------------------------------------
| Social Follows Routes (/api/follows)
|--------------------------------------------------------------------------
*/
Route::prefix('follows')->group(function () {
    // Public follow counts
    Route::get('/get-followers/{userId}', [UserFollowController::class, 'followers'])->name('follows.followers');
    Route::get('/get-following/{userId}', [UserFollowController::class, 'following'])->name('follows.following');

    // Authenticated follow actions
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/follow-user/{userId}', [UserFollowController::class, 'follow'])->name('follows.follow');
        Route::delete('/unfollow-user/{userId}', [UserFollowController::class, 'unfollow'])->name('follows.unfollow');
        Route::get('/get-follow-status/{userId}', [UserFollowController::class, 'status'])->name('follows.status');
    });

    // Compatibility aliases
    Route::get('/{userId}/followers', [UserFollowController::class, 'followers']);
    Route::get('/{userId}/following', [UserFollowController::class, 'following']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/{userId}', [UserFollowController::class, 'follow']);
        Route::delete('/{userId}', [UserFollowController::class, 'unfollow']);
        Route::get('/{userId}/status', [UserFollowController::class, 'status']);
    });
});

/*
|--------------------------------------------------------------------------
| Categories Catalog Routes (/api/categories)
|--------------------------------------------------------------------------
*/
Route::prefix('categories')->group(function () {
    Route::get('/get-categories', [CategoryController::class, 'index'])->name('categories.list');
    Route::get('/get-category/{slug}', [CategoryController::class, 'show'])->name('categories.show');

    // Compatibility aliases
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/{slug}', [CategoryController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Public Products Catalog Routes (/api/products)
|--------------------------------------------------------------------------
*/
Route::prefix('products')->group(function () {
    Route::get('/get-products', [ProductController::class, 'index'])->name('products.list');
    Route::get('/get-featured-products', [ProductController::class, 'featured'])->name('products.featured');
    Route::get('/get-product/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/get-active-campaign/{slug}', [ProductController::class, 'activeCampaign'])->name('products.active-campaign');

    // Compatibility aliases
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/featured', [ProductController::class, 'featured']);
    Route::get('/{slug}', [ProductController::class, 'show']);
    Route::get('/{slug}/active-campaign', [ProductController::class, 'activeCampaign']);
});

/*
|--------------------------------------------------------------------------
| Merchants & Sellers Directory Routes (/api/sellers)
|--------------------------------------------------------------------------
*/
Route::prefix('sellers')->group(function () {
    Route::get('/get-sellers', [SellerController::class, 'index'])->name('sellers.list');
    Route::get('/get-seller/{slug}', [SellerController::class, 'show'])->name('sellers.show');
    Route::middleware('auth:sanctum')->post('/apply-seller', [SellerController::class, 'apply'])->name('sellers.apply');

    // Compatibility aliases
    Route::get('/', [SellerController::class, 'index']);
    Route::get('/{slug}', [SellerController::class, 'show']);
    Route::middleware('auth:sanctum')->post('/apply', [SellerController::class, 'apply']);
});

/*
|--------------------------------------------------------------------------
| Checkout & Orders Routes (/api/checkout, /api/orders)
|--------------------------------------------------------------------------
*/
Route::prefix('checkout')->group(function () {
    Route::post('/get-checkout-quote', [OrderController::class, 'quote'])->name('checkout.quote');
    // Compatibility alias
    Route::post('/quote', [OrderController::class, 'quote']);
});

Route::prefix('orders')->middleware('auth:sanctum')->group(function () {
    Route::get('/get-orders', [OrderController::class, 'index'])->name('orders.list');
    Route::post('/add-order', [OrderController::class, 'store'])->name('orders.create');
    Route::get('/get-order/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/get-order-status-history/{id}', [OrderController::class, 'history'])->name('orders.history');
    Route::post('/cancel-order/{id}', [OrderController::class, 'cancel'])->name('orders.cancel');

    // Compatibility aliases
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/{id}', [OrderController::class, 'show']);
    Route::get('/{id}/history', [OrderController::class, 'history']);
    Route::post('/{id}/cancel', [OrderController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Customer Delivery Addresses (/api/addresses)
|--------------------------------------------------------------------------
*/
Route::prefix('addresses')->middleware('auth:sanctum')->group(function () {
    Route::get('/get-addresses', [AddressController::class, 'index'])->name('addresses.list');
    Route::post('/add-address', [AddressController::class, 'store'])->name('addresses.create');
    Route::get('/get-address/{id}', [AddressController::class, 'show'])->name('addresses.show');
    Route::match(['put', 'patch'], '/update-address/{id}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('/delete-address/{id}', [AddressController::class, 'destroy'])->name('addresses.delete');
    Route::patch('/set-default-address/{id}', [AddressController::class, 'setDefault'])->name('addresses.set-default');

    // Compatibility aliases
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::get('/{id}', [AddressController::class, 'show']);
    Route::put('/{id}', [AddressController::class, 'update']);
    Route::delete('/{id}', [AddressController::class, 'destroy']);
    Route::patch('/{id}/default', [AddressController::class, 'setDefault']);
});

/*
|--------------------------------------------------------------------------
| In-App Notifications (/api/notifications)
|--------------------------------------------------------------------------
*/
Route::prefix('notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('/get-notifications', [NotificationController::class, 'index'])->name('notifications.list');
    Route::get('/get-unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::get('/get-notification/{id}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::patch('/mark-notification-read/{id}', [NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
    Route::post('/mark-all-notifications-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');

    // Compatibility aliases
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/{id}', [NotificationController::class, 'show']);
    Route::patch('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
});

/*
|--------------------------------------------------------------------------
| My Picks (Recommender Curation) (/api/picks)
|--------------------------------------------------------------------------
*/
Route::prefix('picks')->group(function () {
    // Public lookups
    Route::get('/get-user-picks/{username}', [UserPickController::class, 'userPicks'])->name('picks.user-picks');
    Route::get('/lookup-pick-code/{code}', [UserPickController::class, 'lookupCode'])->name('picks.lookup-code');

    // Authenticated curation
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/get-my-picks', [UserPickController::class, 'index'])->name('picks.my-list');
        Route::post('/add-pick', [UserPickController::class, 'store'])->name('picks.create');
        Route::match(['put', 'patch'], '/update-pick/{id}', [UserPickController::class, 'update'])->name('picks.update');
        Route::delete('/delete-pick/{id}', [UserPickController::class, 'destroy'])->name('picks.delete');
    });

    // Compatibility aliases
    Route::get('/user/{username}', [UserPickController::class, 'userPicks']);
    Route::get('/code/{code}', [UserPickController::class, 'lookupCode']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/my', [UserPickController::class, 'index']);
        Route::post('/', [UserPickController::class, 'store']);
        Route::put('/{id}', [UserPickController::class, 'update']);
        Route::delete('/{id}', [UserPickController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Community Storefront Studio & Public Shops (/api/shops)
|--------------------------------------------------------------------------
*/
Route::prefix('shops')->group(function () {
    // Public shop
    Route::get('/get-public-shop/{slug}', [CommunityShopController::class, 'showPublic'])->name('shops.public');

    // Authenticated shop management
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/get-my-shop', [CommunityShopController::class, 'myShop'])->name('shops.my');
        Route::post('/add-shop', [CommunityShopController::class, 'store'])->name('shops.create');
        Route::match(['put', 'patch'], '/update-my-shop', [CommunityShopController::class, 'update'])->name('shops.update');
        Route::get('/get-shop-listings', [CommunityShopController::class, 'listings'])->name('shops.listings');
        Route::post('/add-shop-listing', [CommunityShopController::class, 'storeListing'])->name('shops.add-listing');
        Route::match(['put', 'patch'], '/update-shop-listing/{id}', [CommunityShopController::class, 'updateListing'])->name('shops.update-listing');
        Route::delete('/delete-shop-listing/{id}', [CommunityShopController::class, 'destroyListing'])->name('shops.delete-listing');
        Route::get('/get-shop-sales', [CommunityShopController::class, 'sales'])->name('shops.sales');
    });

    // Compatibility aliases
    Route::get('/slug/{slug}', [CommunityShopController::class, 'showPublic']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/my', [CommunityShopController::class, 'myShop']);
        Route::post('/', [CommunityShopController::class, 'store']);
        Route::put('/my', [CommunityShopController::class, 'update']);
        Route::get('/my/listings', [CommunityShopController::class, 'listings']);
        Route::post('/my/listings', [CommunityShopController::class, 'storeListing']);
        Route::put('/my/listings/{id}', [CommunityShopController::class, 'updateListing']);
        Route::delete('/my/listings/{id}', [CommunityShopController::class, 'destroyListing']);
        Route::get('/my/sales', [CommunityShopController::class, 'sales']);
    });
});

/*
|--------------------------------------------------------------------------
| Community Volume Drops & Group Buys (/api/drops)
|--------------------------------------------------------------------------
*/
Route::prefix('drops')->group(function () {
    // Static endpoints MUST precede {id} wildcard
    Route::get('/get-drops', [CommunityDropController::class, 'publicIndex'])->name('drops.public-feed');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/get-eligible-drop-products', [CommunityDropController::class, 'eligibleProducts'])->name('drops.eligible-products');
        Route::get('/get-my-drops', [CommunityDropController::class, 'myDrops'])->name('drops.my-drops');
        Route::post('/add-drop', [CommunityDropController::class, 'store'])->name('drops.create');
        Route::get('/get-joined-drops', [CommunityDropController::class, 'joinedDrops'])->name('drops.joined');
        Route::post('/join-drop/{id}', [CommunityDropController::class, 'toggleInterest'])->name('drops.join');
    });

    Route::get('/get-drop/{id}', [CommunityDropController::class, 'show'])->name('drops.show');

    // Compatibility aliases
    Route::get('/', [CommunityDropController::class, 'publicIndex']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user/joined', [CommunityDropController::class, 'joinedDrops']);
        Route::post('/{id}/join', [CommunityDropController::class, 'toggleInterest']);
    });
    Route::get('/{id}', [CommunityDropController::class, 'show']);
});

// Community drops legacy compatibility aliases
Route::middleware('auth:sanctum')->prefix('community-drops')->group(function () {
    Route::get('/eligible-products', [CommunityDropController::class, 'eligibleProducts']);
    Route::get('/my', [CommunityDropController::class, 'myDrops']);
    Route::post('/', [CommunityDropController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Community Feed (/api/feed)
|--------------------------------------------------------------------------
*/
Route::prefix('feed')->group(function () {
    Route::get('/get-feed', [CommunityFeedController::class, 'index'])->name('feed.public');
    Route::middleware('auth:sanctum')->get('/get-following-feed', [CommunityFeedController::class, 'following'])->name('feed.following');

    // Compatibility aliases
    Route::get('/', [CommunityFeedController::class, 'index']);
    Route::middleware('auth:sanctum')->get('/following', [CommunityFeedController::class, 'following']);
});

/*
|--------------------------------------------------------------------------
| Earnings, Ledger & Payouts (/api/earnings)
|--------------------------------------------------------------------------
*/
Route::prefix('earnings')->middleware('auth:sanctum')->group(function () {
    Route::get('/get-earnings-summary', [EarningsController::class, 'summary'])->name('earnings.summary');
    Route::get('/get-earnings-ledger', [EarningsController::class, 'ledger'])->name('earnings.ledger');
    Route::post('/request-payout', [EarningsController::class, 'requestPayout'])->name('earnings.request-payout');
    Route::get('/get-payouts', [EarningsController::class, 'payouts'])->name('earnings.payouts');

    // Compatibility aliases
    Route::get('/summary', [EarningsController::class, 'summary']);
    Route::get('/ledger', [EarningsController::class, 'ledger']);
    Route::post('/payouts', [EarningsController::class, 'requestPayout']);
    Route::get('/payouts', [EarningsController::class, 'payouts']);
});

/*
|--------------------------------------------------------------------------
| Community Leaderboards (/api/leaderboards)
|--------------------------------------------------------------------------
*/
Route::prefix('leaderboards')->group(function () {
    Route::get('/get-leaderboards', [LeaderboardController::class, 'index'])->name('leaderboards.index');
    // Compatibility alias
    Route::get('/', [LeaderboardController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Admin Control Center Routes (/api/admin/...)
| Enforced with Sanctum authentication AND EnsureUserIsAdmin middleware
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Executive Overview & Governance
    Route::prefix('overview')->group(function () {
        Route::get('/get-overview', [AdminController::class, 'overview'])->name('admin.overview');
    });

    Route::prefix('users')->group(function () {
        Route::get('/get-users', [AdminController::class, 'users'])->name('admin.users.list');
    });

    Route::prefix('sellers')->group(function () {
        Route::get('/get-sellers', [AdminController::class, 'sellers'])->name('admin.sellers.list');
        Route::patch('/update-seller-status/{id}', [AdminController::class, 'updateSellerStatus'])->name('admin.sellers.update-status');
    });

    // Categories lookup
    Route::prefix('categories')->group(function () {
        Route::get('/get-categories-lookup', [AdminProductController::class, 'categoriesLookup'])->name('admin.categories.lookup');
    });

    // Admin Products Catalog
    Route::prefix('products')->group(function () {
        Route::get('/get-products', [AdminProductController::class, 'index'])->name('admin.products.list');
        Route::post('/add-product', [AdminProductController::class, 'store'])->name('admin.products.create');
        Route::get('/get-product/{id}', [AdminProductController::class, 'show'])->name('admin.products.show');
        Route::match(['put', 'patch'], '/update-product/{id}', [AdminProductController::class, 'update'])->name('admin.products.update');
        Route::delete('/delete-product/{id}', [AdminProductController::class, 'destroy'])->name('admin.products.delete');
        Route::patch('/update-product-moderation/{id}', [AdminController::class, 'updateProduct'])->name('admin.products.moderation');
        Route::post('/upload-images/{id}', [AdminProductController::class, 'uploadImages'])->name('admin.products.upload-images');
        Route::put('/update-images/{id}', [AdminProductController::class, 'updateImages'])->name('admin.products.update-images');
        Route::delete('/delete-image/{id}/{imageId}', [AdminProductController::class, 'deleteImage'])->name('admin.products.delete-image');
    });

    // Admin Attributes Management
    Route::prefix('attributes')->group(function () {
        Route::get('/get-attributes', [AdminAttributeController::class, 'index'])->name('admin.attributes.list');
        Route::post('/add-attribute', [AdminAttributeController::class, 'store'])->name('admin.attributes.create');
        Route::match(['put', 'patch'], '/update-attribute/{id}', [AdminAttributeController::class, 'update'])->name('admin.attributes.update');
        Route::delete('/delete-attribute/{id}', [AdminAttributeController::class, 'destroy'])->name('admin.attributes.delete');
        Route::post('/add-attribute-item/{id}', [AdminAttributeController::class, 'storeItem'])->name('admin.attributes.create-item');
        Route::match(['put', 'patch'], '/update-attribute-item/{id}/{itemId}', [AdminAttributeController::class, 'updateItem'])->name('admin.attributes.update-item');
        Route::delete('/delete-attribute-item/{id}/{itemId}', [AdminAttributeController::class, 'destroyItem'])->name('admin.attributes.delete-item');
    });

    // Admin Drop / Group Buy Campaigns
    Route::prefix('drop-campaigns')->group(function () {
        Route::get('/get-drop-campaigns', [AdminGroupBuyController::class, 'index'])->name('admin.drop-campaigns.list');
        Route::post('/add-drop-campaign', [AdminGroupBuyController::class, 'store'])->name('admin.drop-campaigns.create');
        Route::get('/get-drop-campaign/{id}', [AdminGroupBuyController::class, 'show'])->name('admin.drop-campaigns.show');
        Route::match(['put', 'patch'], '/update-drop-campaign/{id}', [AdminGroupBuyController::class, 'update'])->name('admin.drop-campaigns.update');
        Route::post('/cancel-drop-campaign/{id}', [AdminGroupBuyController::class, 'cancel'])->name('admin.drop-campaigns.cancel');
    });

    // Admin Orders & Fulfillment
    Route::prefix('orders')->group(function () {
        Route::get('/get-orders', [AdminOrderController::class, 'index'])->name('admin.orders.list');
        Route::get('/get-order/{id}', [AdminOrderController::class, 'show'])->name('admin.orders.show');
        Route::get('/get-order-status-history/{id}', [AdminOrderController::class, 'history'])->name('admin.orders.history');
        Route::patch('/update-order-status/{id}', [AdminOrderController::class, 'updateStatus'])->name('admin.orders.update-status');
    });

    // Admin Business Audit Logs
    Route::prefix('logs')->group(function () {
        Route::get('/get-logs', [AdminLogController::class, 'index'])->name('admin.logs.list');
        Route::get('/get-summary', [AdminLogController::class, 'summary'])->name('admin.logs.summary');
        Route::get('/get-log/{id}', [AdminLogController::class, 'show'])->name('admin.logs.show');
    });

    // Admin Point of Sale (POS) & Customer Address Management
    Route::prefix('pos')->group(function () {
        Route::get('/search-products', [AdminPosController::class, 'products'])->name('admin.pos.products');
        Route::get('/search-customers', [AdminPosController::class, 'customers'])->name('admin.pos.customers');
        Route::post('/get-sale-quote', [AdminPosController::class, 'quote'])->name('admin.pos.quote');
        Route::post('/add-sale', [AdminPosController::class, 'store'])->name('admin.pos.create-sale');
        Route::get('/get-sale/{id}', [AdminPosController::class, 'show'])->name('admin.pos.show-sale');
    });

    Route::prefix('customers')->group(function () {
        Route::get('/get-addresses/{customerId}', [AdminPosController::class, 'customerAddresses'])->name('admin.customers.addresses');
        Route::post('/add-address/{customerId}', [AdminPosController::class, 'storeCustomerAddress'])->name('admin.customers.add-address');
    });

    // Admin Community Commerce Oversight
    Route::prefix('community')->group(function () {
        Route::get('/get-shops', [AdminCommunityController::class, 'shops'])->name('admin.community.shops');
        Route::patch('/update-shop-status/{id}', [AdminCommunityController::class, 'updateShopStatus'])->name('admin.community.update-shop-status');
        Route::get('/get-payouts', [AdminCommunityController::class, 'payouts'])->name('admin.community.payouts');
        Route::post('/process-payout/{id}', [AdminCommunityController::class, 'processPayout'])->name('admin.community.process-payout');
        Route::get('/get-settlement-report', [AdminCommunityController::class, 'settlementReport'])->name('admin.community.settlement-report');
    });

    // ------------------------------------------------------------------------
    // Admin Compatibility Aliases
    // ------------------------------------------------------------------------
    Route::get('/overview', [AdminController::class, 'overview']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/sellers', [AdminController::class, 'sellers']);
    Route::patch('/sellers/{id}/status', [AdminController::class, 'updateSellerStatus']);
    Route::patch('/products/{id}', [AdminController::class, 'updateProduct']);

    Route::get('/categories-lookup', [AdminProductController::class, 'categoriesLookup']);
    Route::get('/products', [AdminProductController::class, 'index']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::get('/products/{id}', [AdminProductController::class, 'show']);
    Route::put('/products/{id}', [AdminProductController::class, 'update']);
    Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);
    Route::post('/products/{id}/images', [AdminProductController::class, 'uploadImages']);
    Route::put('/products/{id}/images', [AdminProductController::class, 'updateImages']);
    Route::delete('/products/{id}/images/{imageId}', [AdminProductController::class, 'deleteImage']);

    Route::get('/attributes', [AdminAttributeController::class, 'index']);
    Route::post('/attributes', [AdminAttributeController::class, 'store']);
    Route::put('/attributes/{id}', [AdminAttributeController::class, 'update']);
    Route::delete('/attributes/{id}', [AdminAttributeController::class, 'destroy']);
    Route::post('/attributes/{id}/items', [AdminAttributeController::class, 'storeItem']);
    Route::put('/attributes/{id}/items/{itemId}', [AdminAttributeController::class, 'updateItem']);
    Route::delete('/attributes/{id}/items/{itemId}', [AdminAttributeController::class, 'destroyItem']);

    Route::get('/drop-campaigns', [AdminGroupBuyController::class, 'index']);
    Route::post('/drop-campaigns', [AdminGroupBuyController::class, 'store']);
    Route::get('/drop-campaigns/{id}', [AdminGroupBuyController::class, 'show']);
    Route::put('/drop-campaigns/{id}', [AdminGroupBuyController::class, 'update']);
    Route::post('/drop-campaigns/{id}/cancel', [AdminGroupBuyController::class, 'cancel']);

    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::get('/orders/{id}/history', [AdminOrderController::class, 'history']);
    Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

    Route::get('/logs', [AdminLogController::class, 'index']);
    Route::get('/logs/summary', [AdminLogController::class, 'summary']);
    Route::get('/logs/{id}', [AdminLogController::class, 'show']);

    Route::get('/pos/products', [AdminPosController::class, 'products']);
    Route::get('/pos/customers', [AdminPosController::class, 'customers']);
    Route::get('/customers/{customerId}/addresses', [AdminPosController::class, 'customerAddresses']);
    Route::post('/customers/{customerId}/addresses', [AdminPosController::class, 'storeCustomerAddress']);
    Route::post('/pos/quote', [AdminPosController::class, 'quote']);
    Route::post('/pos/orders', [AdminPosController::class, 'store']);
    Route::get('/pos/orders/{id}', [AdminPosController::class, 'show']);

    Route::get('/community/shops', [AdminCommunityController::class, 'shops']);
    Route::patch('/community/shops/{id}/status', [AdminCommunityController::class, 'updateShopStatus']);
    Route::get('/community/payouts', [AdminCommunityController::class, 'payouts']);
    Route::post('/community/payouts/{id}/process', [AdminCommunityController::class, 'processPayout']);
    Route::get('/community/settlement-report', [AdminCommunityController::class, 'settlementReport']);
});
