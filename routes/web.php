<?php

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/status');
Route::get('/status', StatusController::class);
