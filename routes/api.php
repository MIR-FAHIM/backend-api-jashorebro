<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FriendController;
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

// Instagram-style public user profile
Route::get('/users/{username}', [FriendController::class, 'userProfile']);

