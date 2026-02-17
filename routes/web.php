<?php

use App\Http\Controllers\students\PrivateImageController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

require __DIR__ . '/auth.php';


Route::get('/private-image/{path}', [PrivateImageController::class, 'show'])
    ->where('path', '.*')
    ->middleware('auth:sanctum')
    ->name('private.image');
