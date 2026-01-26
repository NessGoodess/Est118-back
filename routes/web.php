<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

require __DIR__.'/auth.php';

Route::get('/private-image/{path}', function ($path) {
    if (! Storage::disk('private')->exists($path)) {
        abort(404);
    }

    return Storage::disk('private')->get($path);
})
    ->where('path', '.*')
    ->middleware('auth:sanctum');
