<?php

use Illuminate\Support\Facades\Route;

Route::prefix('server')->group(function () {
    require __DIR__ . '/server.php';
});
