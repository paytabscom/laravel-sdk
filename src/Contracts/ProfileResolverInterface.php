<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Contracts;

use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

interface ProfileResolverInterface
{
    /**
     * Resolve an alternative profile before signature validation.
     * Return null to keep the default profile from configuration.
     *
     * @param  Ipn|Browser  $transactionResult  The transaction result (IPN or Browser callback)
     * @return Profile|null The resolved profile or null for default
     */
    public function resolveProfile(Ipn|Browser $transactionResult): ?Profile;
}
