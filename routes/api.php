<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberEquipmentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api'
], function () {
    // Public (no JWT)
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Authenticated
    Route::middleware('auth:api')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('courts', CourtController::class)->only(['index', 'show']);
        Route::apiResource('products', ProductController::class)->only(['index', 'show']);
        Route::get('/products/{product}/reviews', [ProductReviewController::class, 'index']);
        Route::post('/products/{product}/reviews', [ProductReviewController::class, 'store']);

        Route::get('/members/{member}', [MemberController::class, 'show']);
        Route::get('/members/{member}/custom-equipments', [MemberEquipmentController::class, 'index']);
        Route::post('/members/{member}/custom-equipments', [MemberEquipmentController::class, 'store']);
        Route::post('/members/{member}/equipment/{product}', [MemberController::class, 'attachEquipment']);
        Route::delete('/members/{member}/equipment/{product}', [MemberController::class, 'detachEquipment']);
        Route::delete('/custom-equipments/{id}', [MemberEquipmentController::class, 'destroy']);

        Route::post('/bookings', [BookingController::class, 'store']);
        Route::post('/orders', [OrderController::class, 'store']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::get('/stats', [StatsController::class, 'index']);
            Route::post('/uploads', [UploadController::class, 'store']);
            Route::get('/members', [MemberController::class, 'index']);

            Route::apiResource('courts', CourtController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy']);
        });
    });
});
