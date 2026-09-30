<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Responses;

use Paytabs\Sdk\Response\ResponseDirectInterface;

class PaymentResponse
{
    public function __construct(
        private ResponseDirectInterface $rawResponse
    ) {}

    /**
     * Check if the API request was processed successfully without errors.
     */
    public function isSuccess(): bool
    {
        return $this->rawResponse->isProcessed() && ! $this->rawResponse->isFailure();
    }

    /**
     * Check if the response is a redirect (e.g. to a hosted payment page).
     */
    public function isRedirect(): bool
    {
        return $this->rawResponse->isRedirect();
    }

    /**
     * Get the redirect URL if the response is a redirect.
     */
    public function getRedirectUrl(): ?string
    {
        if ($this->isRedirect()) {
            return $this->rawResponse->getRedirect()->redirect_url;
        }

        return null;
    }

    /**
     * Check if the request failed.
     */
    public function isFailure(): bool
    {
        return $this->rawResponse->isFailure();
    }

    /**
     * Get the failure message if any.
     */
    public function getMessage(): string
    {
        if ($this->isFailure()) {
            return $this->rawResponse->getFailure()->message;
        }

        return '';
    }

    /**
     * Get the failure code if any.
     */
    public function getCode(): ?int
    {
        if ($this->isFailure()) {
            return $this->rawResponse->getFailure()->code;
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
