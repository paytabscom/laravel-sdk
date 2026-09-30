<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Paytabs\Laravel\Paytabs;
use Paytabs\Laravel\Responses\TransactionResponse;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Request\Payload\Payloads\Followup;
use Paytabs\Sdk\Request\Payload\Payloads\Followup\Refund;
use Paytabs\Sdk\Request\Payload\Payloads\TransactionQuery;
use Paytabs\Sdk\Request\RequestsFactory;

class TransactionsService
{
    public function __construct(
        private Paytabs $paytabs
    ) {}

    /**
     * Query a transaction status.
     *
     * @param  string  $tran_ref  The original transaction reference
     */
    public function query(string $tran_ref): TransactionResponse
    {
        $payload = (new TransactionQuery)
            ->buildTransactionRef($tran_ref);

        $request = RequestsFactory::createTransactionQuery($payload);

        $response = $this->paytabs->submitRequest($request);

        return new TransactionResponse($response);
    }

    /**
     * Capture an authorized transaction.
     *
     * @param  string  $tran_ref  The original transaction reference
     * @param  string  $cart_id  The cart ID
     * @param  float  $cart_amount  The amount to capture
     * @param  string  $cart_currency  The currency (e.g. 'AED')
     * @param  string  $cart_description  Description for the capture
     */
    public function capture(
        string $tran_ref,
        string $cart_id,
        float $cart_amount,
        string $cart_currency,
        string $cart_description
    ): TransactionResponse {
        $payload = (new Followup)
            ->buildTransaction(TranType::Capture)
            ->buildTransactionRef($tran_ref)
            ->buildCart($cart_id, $cart_currency, $cart_amount, $cart_description);

        $request = RequestsFactory::createPaymentRequest($payload);

        $response = $this->paytabs->submitRequest($request);

        return new TransactionResponse($response);
    }

    /**
     * Refund a captured or sale transaction.
     *
     * @param  string  $tran_ref  The original transaction reference
     * @param  string  $cart_id  The cart ID
     * @param  float  $cart_amount  The amount to refund
     * @param  string  $cart_currency  The currency (e.g. 'AED')
     * @param  string  $cart_description  Description for the refund
     */
    public function refund(
        string $tran_ref,
        string $cart_id,
        float $cart_amount,
        string $cart_currency,
        string $cart_description
    ): TransactionResponse {
        $payload = (new Refund)
            ->buildTransactionRef($tran_ref)
            ->buildCart($cart_id, $cart_currency, $cart_amount, $cart_description);

        $request = RequestsFactory::createPaymentRequest($payload);

        $response = $this->paytabs->submitRequest($request);

        return new TransactionResponse($response);
    }

    /**
     * Void an authorized transaction.
     *
     * @param  string  $tran_ref  The original transaction reference
     * @param  string  $cart_id  The cart ID
     * @param  float  $cart_amount  The void amount (usually 0 for full void, or same as auth)
     * @param  string  $cart_currency  The currency (e.g. 'AED')
     * @param  string  $cart_description  Description for the void
     */
    public function void(
        string $tran_ref,
        string $cart_id,
        float $cart_amount,
        string $cart_currency,
        string $cart_description
    ): TransactionResponse {
        $payload = (new Followup)
            ->buildTransaction(TranType::Void)
            ->buildTransactionRef($tran_ref)
            ->buildCart($cart_id, $cart_currency, $cart_amount, $cart_description);

        $request = RequestsFactory::createPaymentRequest($payload);

        $response = $this->paytabs->submitRequest($request);

        return new TransactionResponse($response);
    }
}
