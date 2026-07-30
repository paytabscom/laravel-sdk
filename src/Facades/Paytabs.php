<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Paytabs\Laravel\Paytabs as PaytabsLaravel;
use Paytabs\Laravel\Services\PaytabsResultProcessor;
use Paytabs\Sdk\Paytabs as PaytabsSdk;
use Paytabs\Sdk\Profile\AbstractEndpoint;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Request\AbstractRequest;
use Paytabs\Sdk\Response\ResponseDirectInterface;

/**
 * @method static PaytabsSdk getInstance()
 * @method static PaytabsSdk usingDefaults()
 * @method static PaytabsSdk usingCredentials(int $profileId, string $serverKey, AbstractEndpoint|string $endpoint)
 * @method static PaytabsSdk usingProfile(Profile $profile)
 * @method static Profile getProfile()
 * @method static PaytabsResultProcessor getResultProcessor(?Profile $profile = null)
 * @method static ResponseDirectInterface submitRequest(AbstractRequest $request)
 *
 * @see PaytabsLaravel
 */
class Paytabs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaytabsLaravel::class;
    }
}
