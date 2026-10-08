<?php

use App\Http\Controllers\Api\AdminAttributeController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminGroupBuyController;
use App\Http\Controllers\Api\AdminLogController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPosController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SellerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JashoreBro API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/otp/send', [AuthController::class, 'sendOtp']);
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user()->load('profile', 'roles');
});

/*
|--------------------------------------------------------------------------
| Friends & Social Graph Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('friends')->group(function () {
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

// Public user profile
Route::get('/users/{username}', [FriendController::class, 'userProfile']);

/*
|--------------------------------------------------------------------------
| Merchants & Public Product Catalog Routes
|--------------------------------------------------------------------------
*/
Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/{slug}', [CategoryController::class, 'show']);
});

Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/featured', [ProductController::class, 'featured']);
    Route::get('/{slug}', [ProductController::class, 'show']);
    Route::get('/{slug}/active-campaign', [ProductController::class, 'activeCampaign']);
});

Route::prefix('sellers')->group(function () {
    Route::get('/', [SellerController::class, 'index']);
    Route::get('/{slug}', [SellerController::class, 'show']);
    Route::middleware('auth:sanctum')->post('/apply', [SellerController::class, 'apply']);
});

/*
|--------------------------------------------------------------------------
| Customer Checkout & Orders Routes
|--------------------------------------------------------------------------
*/
Route::post('/checkout/quote', [OrderController::class, 'quote']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/orders/{id}/history', [OrderController::class, 'history']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);

    // Customer Delivery Addresses
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::get('/addresses/{id}', [AddressController::class, 'show']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);
    Route::patch('/addresses/{id}/default', [AddressController::class, 'setDefault']);

    // In-App Notifications (Customer & Admin context)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('/notifications/{id}', [NotificationController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Admin & Governance Control Center Routes
| Enforced with Sanctum authentication AND EnsureUserIsAdmin middleware
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Executive metrics & user moderation
    Route::get('/overview', [AdminController::class, 'overview']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/sellers', [AdminController::class, 'sellers']);
    Route::patch('/sellers/{id}/status', [AdminController::class, 'updateSellerStatus']);
    Route::patch('/products/{id}', [AdminController::class, 'updateProduct']);

    // Admin Product Catalog CRUD
    Route::get('/categories-lookup', [AdminProductController::class, 'categoriesLookup']);
    Route::get('/products', [AdminProductController::class, 'index']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::get('/products/{id}', [AdminProductController::class, 'show']);
    Route::put('/products/{id}', [AdminProductController::class, 'update']);
    Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);
    Route::post('/products/{id}/images', [AdminProductController::class, 'uploadImages']);
    Route::put('/products/{id}/images', [AdminProductController::class, 'updateImages']);
    Route::delete('/products/{id}/images/{imageId}', [AdminProductController::class, 'deleteImage']);

    // Admin Attribute Management
    Route::get('/attributes', [AdminAttributeController::class, 'index']);
    Route::post('/attributes', [AdminAttributeController::class, 'store']);
    Route::put('/attributes/{id}', [AdminAttributeController::class, 'update']);
    Route::delete('/attributes/{id}', [AdminAttributeController::class, 'destroy']);
    Route::post('/attributes/{id}/items', [AdminAttributeController::class, 'storeItem']);
    Route::put('/attributes/{id}/items/{itemId}', [AdminAttributeController::class, 'updateItem']);
    Route::delete('/attributes/{id}/items/{itemId}', [AdminAttributeController::class, 'destroyItem']);

    // Admin Drop / Group Buy Campaigns
    Route::get('/drop-campaigns', [AdminGroupBuyController::class, 'index']);
    Route::post('/drop-campaigns', [AdminGroupBuyController::class, 'store']);
    Route::get('/drop-campaigns/{id}', [AdminGroupBuyController::class, 'show']);
    Route::put('/drop-campaigns/{id}', [AdminGroupBuyController::class, 'update']);
    Route::post('/drop-campaigns/{id}/cancel', [AdminGroupBuyController::class, 'cancel']);

    // Admin Orders & Fulfillment
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::get('/orders/{id}/history', [AdminOrderController::class, 'history']);
    Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

    // Admin Business Audit Logs
    Route::get('/logs', [AdminLogController::class, 'index']);
    Route::get('/logs/summary', [AdminLogController::class, 'summary']);
    Route::get('/logs/{id}', [AdminLogController::class, 'show']);

    // Admin Point of Sale (POS) & Customer Address Management
    Route::get('/pos/products', [AdminPosController::class, 'products']);
    Route::get('/pos/customers', [AdminPosController::class, 'customers']);
    Route::get('/customers/{customerId}/addresses', [AdminPosController::class, 'customerAddresses']);
    Route::post('/customers/{customerId}/addresses', [AdminPosController::class, 'storeCustomerAddress']);
    Route::post('/pos/quote', [AdminPosController::class, 'quote']);
    Route::post('/pos/orders', [AdminPosController::class, 'store']);
    Route::get('/pos/orders/{id}', [AdminPosController::class, 'show']);
});
