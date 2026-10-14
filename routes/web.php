<?php

declare(strict_types=1);

use App\Infrastructure\Http\ProfilesController;
use Illuminate\Support\Facades\Route;

Route::get('/', ProfilesController::class);
