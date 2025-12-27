<?php

use App\Http\Controllers\NfcCredentialController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});


Route::prefix('reader')->group(function () {
    Route::post('/read-event', [NfcCredentialController::class, 'read']);
});
