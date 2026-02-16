<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'service' => config('app.name'),
        'status'  => 'ok',
        'time'    => now()->toIso8601String(),
    ]);
});
