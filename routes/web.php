<?php

use AljazRomih\GmailTransport\Http\GmailOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('connect', [GmailOAuthController::class, 'connect'])->name('connect');
Route::get('callback', [GmailOAuthController::class, 'callback'])->name('callback');
