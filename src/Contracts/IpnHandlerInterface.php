<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Contracts;

use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;

interface IpnHandlerInterface
{
    /**
     * Handle the IPN payload after it has been verified.
     *
     * @param  AbstractTransactionResult  $transactionResult  The transaction result from PayTabs.
     * @param  Ipn  $mappedPayload  The mapped IPN payload from PayTabs.
     */
    public function handleIpn(
        AbstractTransactionResult $transactionResult,
        Ipn $mappedPayload
    ): void;
}
