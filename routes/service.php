<?php

use App\Http\Controllers\AuthService\AuthServiceController;
use Illuminate\Support\Facades\Route;


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/service-tokens', [AuthServiceController::class, 'index']);
    Route::post('/service-tokens', [AuthServiceController::class, 'store']);
    Route::delete('/service-tokens/{tokenId}', [AuthServiceController::class, 'destroy']);
});
