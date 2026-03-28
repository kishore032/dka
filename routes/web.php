<?php

use App\Http\Controllers\LookupController;
use App\Http\Controllers\RawmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return config('dka.mail_domain')=="*" ? view('welcome_rdka') : view('welcome');
});


Route::post('/inbound', [RawmailController::class, 'receive']);

Route::prefix('.well-known/dka')->group(function () {
    Route::get('/lookup',    [LookupController::class, 'lookup']);
    Route::get('/selectors', [LookupController::class, 'selectors']);
    Route::get('/version',   [LookupController::class, 'version']);
    Route::get('/apis',      [LookupController::class, 'apis']);
});
