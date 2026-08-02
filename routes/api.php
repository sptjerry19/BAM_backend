<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

Route::get('/stats', [StatsController::class, 'index']);

Route::apiResource('courts', CourtController::class);
Route::apiResource('products', ProductController::class);
Route::apiResource('members', MemberController::class)->only(['index', 'show']);

Route::post('/bookings', [BookingController::class, 'store']);
Route::post('/orders', [OrderController::class, 'store']);
