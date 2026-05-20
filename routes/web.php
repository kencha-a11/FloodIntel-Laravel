<?php

use Illuminate\Support\Facades\Route;

Route::prefix('server')->group(function () {
    require __DIR__ . '/server.php';
});

Route::get('/', function () {
    return redirect()->route('login'); // 'login' ang route name mo sa server.php
});