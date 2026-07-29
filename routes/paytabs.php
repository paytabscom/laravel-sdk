<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Paytabs\Laravel\Http\Controllers\PaytabsResultController;

$ipnRoutePath = (string) Config::get('paytabs.ipn_route_path', 'paytabs/ipn');
$ipnMiddlewares = (array) Config::get('paytabs.ipn_route_middleware', ['api']);

$route = Route::post($ipnRoutePath, [PaytabsResultController::class, 'ipn'])
    ->name('paytabs.ipn');

if ($ipnMiddlewares !== []) {
    $route->middleware($ipnMiddlewares);
}
