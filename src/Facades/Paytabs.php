<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Paytabs\Laravel\Paytabs as PaytabsLaravel;

/**
 * @see PaytabsLaravel
 */
class Paytabs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaytabsLaravel::class;
    }
}
