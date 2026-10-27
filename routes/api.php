<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberEquipmentController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\CapitalRivals\RoomController as CapitalRivalsRoomController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
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
        Route::get('/top', [CourtController::class, 'top']);
        Route::get('/picked', [CourtController::class, 'picked']);
        Route::get('/{court}/availability', [CourtController::class, 'availability']);
        Route::get('/{court}', [CourtController::class, 'show']);
    });

    // Guest checkout allowed — member_id optional when logged in
    Route::post('/orders', [OrderController::class, 'store']);

    Route::get('/rankings', [RankingController::class, 'index']);
    Route::get('/athletes/{athlete}', [RankingController::class, 'show']);

    Route::prefix('games/cr')->group(function () {
        Route::post('/rooms', [CapitalRivalsRoomController::class, 'store']);
        Route::post('/rooms/{code}/join', [CapitalRivalsRoomController::class, 'join']);
        Route::post('/rooms/{code}/ready', [CapitalRivalsRoomController::class, 'ready']);
        Route::post('/rooms/{code}/start', [CapitalRivalsRoomController::class, 'start']);
        Route::post('/rooms/{code}/leave', [CapitalRivalsRoomController::class, 'leave']);
        Route::post('/rooms/{code}/roll', [CapitalRivalsRoomController::class, 'roll']);
        Route::post('/rooms/{code}/buy', [CapitalRivalsRoomController::class, 'buy']);
        Route::post('/rooms/{code}/skip', [CapitalRivalsRoomController::class, 'skip']);
        Route::post('/rooms/{code}/pay', [CapitalRivalsRoomController::class, 'pay']);
        Route::post('/rooms/{code}/card', [CapitalRivalsRoomController::class, 'card']);
        Route::post('/rooms/{code}/upgrade', [CapitalRivalsRoomController::class, 'upgrade']);
        Route::post('/rooms/{code}/end-turn', [CapitalRivalsRoomController::class, 'endTurn']);
        Route::post('/rooms/{code}/bankrupt', [CapitalRivalsRoomController::class, 'bankrupt']);
        Route::post('/rooms/{code}/tick', [CapitalRivalsRoomController::class, 'tick']);
        Route::get('/rooms/{code}', [CapitalRivalsRoomController::class, 'snapshot']);
    });

    // News
    Route::get('/news', [NewsController::class, 'index']);
    Route::get('/news/{news}', [NewsController::class, 'show']);

    //Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);

    // Authenticated member actions
    Route::group(['middleware' => 'auth:api'], function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::match(['put', 'post'], '/me', [AuthController::class, 'update']);

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
            Route::patch('/members/{member}/status', [MemberController::class, 'updateStatus']);
            Route::delete('/members/{member}', [MemberController::class, 'destroy']);

            Route::group(['prefix' => 'courts'], function () {
                Route::post('/', [CourtController::class, 'store']);
                Route::put('/{court}', [CourtController::class, 'update']);
                Route::delete('/{court}', [CourtController::class, 'destroy']);
            });

            Route::group(['prefix' => 'news'], function () {
                Route::post('/', [NewsController::class, 'store']);
                Route::put('/{news}', [NewsController::class, 'update']);
                Route::delete('/{news}', [NewsController::class, 'destroy']);
            });

            Route::group(['prefix' => 'categories'], function () {
                Route::post('/', [CategoryController::class, 'store']);
                Route::put('/{category}', [CategoryController::class, 'update']);
                Route::delete('/{category}', [CategoryController::class, 'destroy']);
            });

            Route::group(['prefix' => 'products'], function () {
                Route::post('/', [ProductController::class, 'store']);
                Route::put('/{product}', [ProductController::class, 'update']);
                Route::delete('/{product}', [ProductController::class, 'destroy']);
            });
        });
    });
});
