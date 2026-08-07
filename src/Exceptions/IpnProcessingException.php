<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Exceptions;

use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

/**
 * Base exception for callbacks that were received but not processed.
 *
 * @phpstan-consistent-constructor
 */
class IpnProcessingException extends \RuntimeException
{
    /**
     * Create an exception describing why an IPN delivery was not processed.
     *
     * @param  Ipn  $ipn  The IPN payload that was rejected
     * @param  string  $reason  Short description of the rejection cause
     * @return self The exception instance
     */
    public static function forIpn(Ipn $ipn, string $reason): self
    {
        // Payload properties are typed and non-nullable, so ?? guards against unmapped fields.
        $msg = sprintf(
            'IPN processing error: %s (trace: %s, reference: %s)',
            $reason,
            $ipn->ipn_trace ?? 'unknown',
            $ipn->tran_ref ?? 'unknown',
        );

        return new static($msg);
    }
}
