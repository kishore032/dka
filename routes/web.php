<?php

use App\Http\Controllers\LookupController;
use App\Http\Controllers\RawmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome', ['size' => 'md']);
});

foreach (['lg', 'md', 'sm'] as $size) {
    Route::get("/{$size}", function () use ($size) {
        return view('welcome', ['size' => $size]);
    });
}

Route::post('/inbound', [RawmailController::class, 'receive']);

Route::prefix('.well-known/dka')->group(function () {
    Route::get('/lookup',    [LookupController::class, 'lookup']);
    Route::get('/selectors', [LookupController::class, 'selectors']);
    Route::get('/version',   [LookupController::class, 'version']);
    Route::get('/apis',      [LookupController::class, 'apis']);
});
