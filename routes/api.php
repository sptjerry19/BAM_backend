<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberEquipmentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth',
], function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('/stats', [StatsController::class, 'index']);

    Route::apiResource('courts', CourtController::class);
    Route::apiResource('products', ProductController::class);
    Route::get('/products/{product}/reviews', [ProductReviewController::class, 'index']);
    Route::post('/products/{product}/reviews', [ProductReviewController::class, 'store']);

    Route::apiResource('members', MemberController::class)->only(['index', 'show']);
    Route::get('/members/{member}/custom-equipments', [MemberEquipmentController::class, 'index']);
    Route::post('/members/{member}/custom-equipments', [MemberEquipmentController::class, 'store']);
    Route::post('/members/{member}/equipment/{product}', [MemberController::class, 'attachEquipment']);
    Route::delete('/members/{member}/equipment/{product}', [MemberController::class, 'detachEquipment']);
    Route::delete('/custom-equipments/{id}', [MemberEquipmentController::class, 'destroy']);

    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/orders', [OrderController::class, 'store']);
});
