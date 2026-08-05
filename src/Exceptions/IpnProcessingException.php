<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Exceptions;

use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class IpnProcessingException extends \RuntimeException
{
    /**
     * Create an exception for duplicate IPN delivery.
     *
     * @return self The exception instance
     */
    public static function forIpn(Ipn $ipn): self
    {
        $msg = sprintf(
            'IPN processing error: (trace: %s, reference: %s)',
            $ipn->ipn_trace,
            $ipn->tran_ref,
        );

        return new self($msg);
    }
}
