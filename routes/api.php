<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PublicBookController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Public booking routes (no auth)
Route::prefix('book/{slug}')->group(function () {
    Route::get('config', [PublicBookController::class, 'config']);
    Route::get('slots', [PublicBookController::class, 'slots']);
    Route::post('book', [PublicBookController::class, 'book']);
});

// Public cancel by token
Route::get('cancel/{token}', [BookingController::class, 'cancelPublic']);

// Auth
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);
    Route::post('settings/caldav-discover', [SettingsController::class, 'caldavDiscover']);

    Route::get('bookings', [BookingController::class, 'index']);
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
});
