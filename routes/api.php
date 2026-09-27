<?php

use App\Http\Controllers\UsageEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MerchantDashboardController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('throttle:usage-events')->group(function () {
    Route::post('/usage', [UsageEventController::class, 'store']);
});

Route::get('/merchants/{merchant}/dashboard', [MerchantDashboardController::class, 'show']);
