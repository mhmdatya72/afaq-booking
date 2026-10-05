<?php

use App\Http\Controllers\Api\V1\BookingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:bookings');
    Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->whereNumber('id');
});
