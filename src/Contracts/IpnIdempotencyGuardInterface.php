<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Contracts;

use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

interface IpnIdempotencyGuardInterface
{
    /**
     * Acquire processing ownership for an IPN payload.
     * Returns true only for the first delivery within the configured TTL.
     *
     * @param  Ipn  $payload  The IPN payload to check
     * @return bool True if this is the first delivery, false if duplicate
     */
    public function acquire(Ipn $payload): bool;

    public function release(Ipn $payload): void;
}
