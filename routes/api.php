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

Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to the API',
    ]);
});

Route::group([
    'middleware' => 'api',
], function () {
   // Public (no JWT) — browse courts & products
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::group(['prefix' => 'products'], function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/bestsellers', [ProductController::class, 'bestsellers']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::get('/{product}/reviews', [ProductReviewController::class, 'index']);
        Route::get('/{product}/reviews/{review}', [ProductReviewController::class, 'show']);
    });

    Route::group(['prefix' => 'courts'], function () {
        Route::get('/', [CourtController::class, 'index']);
        Route::get('/{court}', [CourtController::class, 'show']);
    });

    // Guest checkout allowed — member_id optional when logged in
    Route::post('/orders', [OrderController::class, 'store']);

    // Authenticated member actions
    Route::group(['middleware' => 'auth:api'], function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/products/{product}/reviews', [ProductReviewController::class, 'store']);

        Route::get('/members/{member}', [MemberController::class, 'show']);
        Route::get('/members/{member}/custom-equipments', [MemberEquipmentController::class, 'index']);
        Route::post('/members/{member}/custom-equipments', [MemberEquipmentController::class, 'store']);
        Route::post('/members/{member}/equipment/{product}', [MemberController::class, 'attachEquipment']);
        Route::delete('/members/{member}/equipment/{product}', [MemberController::class, 'detachEquipment']);
        Route::delete('/custom-equipments/{id}', [MemberEquipmentController::class, 'destroy']);

        Route::post('/bookings', [BookingController::class, 'store']);

        // Admin only — write / manage
        Route::middleware('admin')->group(function () {
            Route::get('/stats', [StatsController::class, 'index']);
            Route::post('/uploads', [UploadController::class, 'store']);
            Route::get('/members', [MemberController::class, 'index']);

            Route::group(['prefix' => 'courts'], function () {
                Route::post('/', [CourtController::class, 'store']);
                Route::put('/{court}', [CourtController::class, 'update']);
                Route::delete('/{court}', [CourtController::class, 'destroy']);
            });

            Route::group(['prefix' => 'products'], function () {
                Route::post('/', [ProductController::class, 'store']);
                Route::put('/{product}', [ProductController::class, 'update']);
                Route::delete('/{product}', [ProductController::class, 'destroy']);
            });
        });
    });
});
