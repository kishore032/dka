<?php

use App\Http\Controllers\LookupController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/lookup',    [LookupController::class, 'lookup']);
    Route::get('/selectors', [LookupController::class, 'selectors']);
    Route::get('/version',   [LookupController::class, 'version']);
    Route::get('/apis',      [LookupController::class, 'apis']);
});


