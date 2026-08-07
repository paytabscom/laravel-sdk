<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Results;

use Illuminate\Http\JsonResponse;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Throwable;

/**
 * The result of verifying a PayTabs callback: the outcome, and the payload when there is one.
 */
final class IpnResult
{
    /**
     * @param  IpnOutcome  $outcome  What happened to the delivery
     * @param  Ipn|null  $payload  The verified payload, present only when the outcome is Processed
     * @param  Throwable|null  $cause  The underlying error, present only when verification failed
     * @param  string|null  $reason  Human readable description of a non-Processed outcome
     */
    public function __construct(
        public readonly IpnOutcome $outcome,
        public readonly ?Ipn $payload = null,
        public readonly ?Throwable $cause = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Build a successful result carrying the verified payload.
     *
     * @param  Ipn  $payload  The verified IPN payload
     * @return self The processed result
     */
    public static function processed(Ipn $payload): self
    {
        return new self(IpnOutcome::Processed, $payload);
    }

    /**
     * Build a rejected result.
     *
     * @param  IpnOutcome  $outcome  The rejection outcome, must not be Processed
     * @param  string  $reason  Human readable description of the rejection
     * @param  Throwable|null  $cause  The underlying error, when one exists
     * @return self The rejected result
     */
    public static function rejected(IpnOutcome $outcome, string $reason, ?Throwable $cause = null): self
    {
        return new self($outcome, null, $cause, $reason);
    }

    public function isProcessed(): bool
    {
        return $this->outcome === IpnOutcome::Processed;
    }

    /**
     * Get the verified payload, or null when the delivery was not processed.
     *
     * @return Ipn|null The verified payload
     */
    public function payload(): ?Ipn
    {
        return $this->payload;
    }

    /**
     * Map the outcome to the JSON response returned to PayTabs.
     *
     * @return JsonResponse The response for this result
     */
    public function toResponse(): JsonResponse
    {
        return $this->outcome->toResponse();
    }
}
