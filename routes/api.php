<?php

declare(strict_types=1);

use App\Http\Controllers\IndexController;
use App\Http\Controllers\VinDecoderController;
use App\Http\Middleware\CheckApiKey;
use App\Http\Middleware\CheckUsageMiddleware;
use Hypervel\Support\Facades\Route;

Route::any('/', [IndexController::class, 'index']);


Route::group('/v1', function () {
    Route::post('/products/partner/vin-decoder', [VinDecoderController::class, 'decode']);
}, ['middleware' => [CheckApiKey::class, CheckUsageMiddleware::class]]);
