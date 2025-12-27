<?php

use App\Http\Controllers\NfcCredentialController;
use App\Http\Controllers\TelegramController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});


Route::prefix('reader')->group(function () {
    Route::post('/read-event', [NfcCredentialController::class, 'read']);
});


Route::post('/telegram/webhook', [TelegramController::class, 'webhook']);
