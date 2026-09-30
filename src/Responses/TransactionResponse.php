<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Responses;

use Paytabs\Sdk\Response\ResponseDirectInterface;

class TransactionResponse
{
    public function __construct(
        private ResponseDirectInterface $rawResponse
    ) {}

    /**
     * Check if the API request was processed successfully.
     */
    public function isSuccess(): bool
    {
        return $this->rawResponse->isProcessed() && ! $this->rawResponse->isFailure();
    }

    /**
     * Check if the transaction was Authorized/Approved ('A').
     */
    public function isAuthorized(): bool
    {
        $paymentResult = $this->getPaymentResult();

        if ($paymentResult && isset($paymentResult->response_status)) {
            return $paymentResult->response_status === 'A';
        }

        return false;
    }

    /**
     * Get the transaction reference.
     */
    public function getTranRef(): ?string
    {
        return $this->getPayloadProperty('tran_ref');
    }

    /**
     * Get the API response message.
     */
    public function getMessage(): string
    {
        $paymentResult = $this->getPaymentResult();

        if ($paymentResult && isset($paymentResult->response_message)) {
            return $paymentResult->response_message;
        }

        return '';
    }

    /**
     * Get the cart ID associated with the transaction.
     */
    public function getCartId(): ?string
    {
        return $this->getPayloadProperty('cart_id');
    }

    /**
     * Get the cart amount.
     */
    public function getCartAmount(): ?float
    {
        $amount = $this->getPayloadProperty('cart_amount');

        return $amount !== null ? (float) $amount : null;
    }

    /**
     * Get the cart currency.
     */
    public function getCartCurrency(): ?string
    {
        return $this->getPayloadProperty('cart_currency');
    }

    /**
     * Get the transaction type (e.g. Sale, Auth, Capture, Refund, Void).
     */
    public function getTranType(): ?string
    {
        return $this->getPayloadProperty('tran_type');
    }

    /**
     * Get the payment result object containing status details.
     */
    public function getPaymentResult(): ?object
    {
        return $this->getPayloadProperty('payment_result');
    }

    /**
     * Extract a property directly from the mapped payload.
     *
     * @return mixed
     */
    private function getPayloadProperty(string $property)
    {
        if ($this->rawResponse->isFailure()) {
            return null;
        }

        $payload = $this->rawResponse->getPayloadMapped();

        if (isset($payload->{$property})) {
            return $payload->{$property};
        }

        return null;
    }

    /**
     * Access the raw ResponseDirectInterface from the core SDK.
     */
    public function raw(): ResponseDirectInterface
    {
        return $this->rawResponse;
    }
}
