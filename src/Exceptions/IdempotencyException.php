<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Exceptions;

class IdempotencyException extends \RuntimeException
{
    /**
     * Create an exception for duplicate IPN delivery.
     *
     * @return self The exception instance
     */
    public static function duplicateDelivery(): self
    {
        return new self('Idempotency violation: Duplicate IPN delivery detected.');
    }
}
