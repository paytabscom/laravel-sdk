<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Exceptions\InvalidConfigurationException;
use Paytabs\Laravel\Exceptions\InvalidPayloadException;
use Paytabs\Laravel\Results\IpnResult;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Throwable;

class PaytabsResultProcessor
{
    private readonly CallbackVerifier $verifier;

    private readonly DeliveryGuards $guards;

    /**
     * Create a new PayTabs result processor.
     *
     * @param  Container  $container  The Laravel container
     * @param  Profile|null  $profile  Optional profile for validation
     * @param  IpnIdempotencyGuardInterface|null  $idempotencyGuard  Optional idempotency guard
     * @param  Request|null  $request  Optional request, resolved from the container when omitted
     */
    public function __construct(
        private readonly Container $container,
        public readonly ?Profile $profile = null,
        ?IpnIdempotencyGuardInterface $idempotencyGuard = null,
        ?Request $request = null,
    ) {
        $this->verifier = new CallbackVerifier($container, $profile, $request);
        $this->guards = new DeliveryGuards($container, $idempotencyGuard);
    }

    /**
     * Dispatch an IPN from the current request.
     *
     * @return IpnOutcome The outcome of the IPN dispatch
     */
    public function dispatchIpn(): IpnOutcome
    {
        $result = $this->handleIpn(true);
        $ipnData = $result->payload;

        if ($ipnData === null) {
            Log::error('PayTabs IPN was not processed.', [
                'outcome' => $result->outcome->name,
                'reason' => $result->reason,
                'exception' => $result->cause?->getMessage(),
            ]);

            return $result->outcome;
        }

        // Dispatched separately so a handler throwing is never reported as Processed.
        return $this->runIpnHandler($ipnData);
    }

    /**
     * Handle an IPN callback with idempotency check.
     *
     * @param  bool  $idempotencyCheck  Whether to apply the time and duplicate guards
     * @return IpnResult The outcome, carrying the verified payload when it is Processed
     */
    public function handleIpn(bool $idempotencyCheck = true): IpnResult
    {
        return $this->handleCallback($idempotencyCheck);
    }

    /**
     * Handle a callback with optional idempotency check.
     *
     * Verification failures and guard rejections are returned as outcomes, not thrown.
     *
     * @param  bool  $idempotencyCheck  Whether to apply the time and duplicate guards
     * @return IpnResult The outcome, carrying the verified payload when it is Processed
     */
    public function handleCallback(bool $idempotencyCheck = true): IpnResult
    {
        try {
            $ipnData = $this->verifier->verifyIpn();
        } catch (Throwable $e) {
            return $this->rejectionFor($e);
        }

        return $idempotencyCheck
            ? $this->applyGuards($ipnData)
            : IpnResult::processed($ipnData);
    }

    /**
     * Handle a browser redirect callback.
     *
     * @return Browser The verified browser callback payload
     */
    public function handleRedirect(): Browser
    {
        return $this->verifier->verifyBrowser();
    }

    /**
     * Check if an IPN should be processed based on the time and idempotency guards.
     *
     * Note: on success this acquires the idempotency lock. Call it at most once per
     * delivery, and call idempotencyRelease() if your own processing then fails.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if this is the first delivery, false if stale or duplicate
     */
    public function shouldProcessIpn(Ipn $ipn): bool
    {
        return $this->guards->shouldProcess($ipn);
    }

    /**
     * Reject IPNs whose transaction time falls outside the accepted window.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if the transaction time is within the accepted window
     */
    public function timeGuard(Ipn $ipn): bool
    {
        return $this->guards->isFresh($ipn);
    }

    /**
     * Acquire processing ownership for an IPN delivery.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if this is the first delivery, false if duplicate
     */
    public function idempotencyGuard(Ipn $ipn): bool
    {
        return $this->guards->acquire($ipn);
    }

    /**
     * Release a previously acquired idempotency lock so the delivery can be retried.
     *
     * @param  Ipn  $ipn  The IPN payload whose lock should be released
     */
    public function idempotencyRelease(Ipn $ipn): void
    {
        $this->guards->release($ipn);
    }

    /**
     * Classify a verification failure into an outcome.
     *
     * @param  Throwable  $e  The failure raised while verifying the callback
     * @return IpnResult The rejected result carrying the original failure
     */
    private function rejectionFor(Throwable $e): IpnResult
    {
        [$outcome, $reason] = match (true) {
            $e instanceof InvalidSignatureException => [
                IpnOutcome::InvalidSignature,
                'PayTabs callback rejected: invalid signature.',
            ],
            // Malformed payloads can never succeed on retry, so they are not HandlerFailed.
            $e instanceof InvalidPayloadException => [IpnOutcome::InvalidPayload, $e->getMessage()],
            $e instanceof JsonException => [IpnOutcome::InvalidPayload, 'PayTabs callback body is not valid JSON.'],
            $e instanceof InvalidConfigurationException => [
                IpnOutcome::HandlerFailed,
                'PayTabs callback profile resolver misconfiguration.',
            ],
            default => [IpnOutcome::HandlerFailed, 'PayTabs callback verification failed.'],
        };

        return IpnResult::rejected($outcome, $reason, $e);
    }

    /**
     * Apply the time and duplicate guards to a verified payload.
     *
     * @param  Ipn  $ipnData  The verified IPN payload
     * @return IpnResult Processed when both guards pass, otherwise the rejection
     */
    private function applyGuards(Ipn $ipnData): IpnResult
    {
        try {
            $rejection = match (true) {
                ! $this->guards->isFresh($ipnData) => IpnResult::rejected(IpnOutcome::Stale, 'Stale delivery'),
                ! $this->guards->acquire($ipnData) => IpnResult::rejected(IpnOutcome::Duplicate, 'Duplicate delivery'),
                default => null,
            };
        } catch (Throwable $e) {
            // A guard backend outage must not escape as an unhandled exception from the IPN endpoint.
            return IpnResult::rejected(IpnOutcome::HandlerFailed, 'PayTabs callback guard evaluation failed.', $e);
        }

        return $rejection ?? IpnResult::processed($ipnData);
    }

    /**
     * Run the configured handler for a verified IPN.
     *
     * @param  Ipn  $ipnData  The verified IPN payload
     * @return IpnOutcome Processed on success, HandlerFailed if the handler threw
     */
    private function runIpnHandler(Ipn $ipnData): IpnOutcome
    {
        // Resolved before dispatch so a misconfiguration is not reported as a handler crash.
        try {
            $handler = PaytabsResolver::resolveIpnHandler($this->container);
        } catch (InvalidConfigurationException $e) {
            return $this->failHandler($ipnData, 'PayTabs IPN handler is not configured correctly.', $e);
        }

        try {
            $handler->handleIpn($this->verifier->ipnResult(), $ipnData);
        } catch (Throwable $e) {
            return $this->failHandler($ipnData, 'PayTabs IPN handler execution failed.', $e);
        }

        return IpnOutcome::Processed;
    }

    /**
     * Log a handler failure and free the lock so PayTabs can retry.
     *
     * @param  Ipn  $ipnData  The verified IPN payload
     * @param  string  $message  What failed
     * @param  Throwable  $e  The underlying error
     * @return IpnOutcome Always HandlerFailed
     */
    private function failHandler(Ipn $ipnData, string $message, Throwable $e): IpnOutcome
    {
        Log::error($message, [
            'tran_ref' => $ipnData->tran_ref ?? null,
            'exception' => $e->getMessage(),
        ]);

        $this->guards->release($ipnData);

        return IpnOutcome::HandlerFailed;
    }
}
