<?php

use App\Http\Controllers\LookupController;
use App\Http\Controllers\RawmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return config('dka.mail_domain')=="*" ? view('dka') : view('welcome');
});


Route::post('/inbound', [RawmailController::class, 'receive']);

Route::prefix('.well-known/dka')->group(function () {
    Route::get('/lookup',  [LookupController::class, 'lookup']);
    Route::get('/version', [LookupController::class, 'version']);
    Route::get('/apis',      [LookupController::class, 'apis']);
});


// Route::get('/dka', fn() => view('dka'));

Route::get('/dka_old', fn() => view('dka_old'));

Route::get('/whitepaper', fn() => view('whitepaper'));
