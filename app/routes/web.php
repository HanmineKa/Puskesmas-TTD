<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/{path}', 'app')
    ->where('path', '^(?!api(?:/|$)|sanctum(?:/|$)).*');
