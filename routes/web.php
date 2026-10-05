<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/laravel-health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Laravel 10 is successfully integrated into Job Request PHU!'
    ]);
});
