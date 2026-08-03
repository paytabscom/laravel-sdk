<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Laravel\Exceptions\IdempotencyException;
use Paytabs\Laravel\Exceptions\InvalidPayloadException;
use Paytabs\Laravel\Paytabs;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;
use Paytabs\Sdk\Response\Responses\Webhook\TransactionResult\BrowserAsPost;
use Paytabs\Sdk\Response\Responses\Webhook\TransactionResult\Callback;
use Throwable;

class PaytabsResultProcessor
{
    private ?AbstractTransactionResult $resultIpn = null;

    private ?AbstractTransactionResult $resultBrowser = null;

    /**
     * Create a new PayTabs result processor.
     *
     * @param  Container  $container  The Laravel container
     * @param  Profile|null  $profile  Optional profile for validation
     * @param  IpnIdempotencyGuardInterface|null  $idempotencyGuard  Optional idempotency guard
     */
    public function __construct(
        private readonly Container $container,
        public readonly ?Profile $profile = null,
        private readonly ?IpnIdempotencyGuardInterface $idempotencyGuard = null,
    ) {}

    private function getIpnResult(): AbstractTransactionResult
    {
        if ($this->resultIpn === null) {
            $this->resultIpn = Callback::init();
        }

        return $this->resultIpn;
    }

    private function getBrowserResult(): AbstractTransactionResult
    {
        if ($this->resultBrowser === null) {
            $this->resultBrowser = BrowserAsPost::init();
        }

        return $this->resultBrowser;
    }

    /**
     * Dispatch an IPN from the current request.
     *
     * @return bool True if signature was valid, false otherwise
     */
    public function dispatchIpn(): bool
    {
        try {
            $ipnData = $this->handleIpn();

            if ($this->shouldProcessIpn($ipnData)) {
                $this->dispatchVerifiedTransactionResult($this->getIpnResult(), $ipnData);
            }

            return true;
        } catch (InvalidSignatureException $e) {
            Log::warning('PayTabs IPN rejected: invalid signature.', [
                'exception' => $e,
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('PayTabs IPN handler execution failed.', [
                'exception' => $e,
            ]);

            return (bool) Config::get('paytabs.ack_on_handler_exception', true);
        }
    }

    /**
     * Handle an IPN callback with idempotency check.
     *
     * @return Ipn The verified IPN payload
     */
    public function handleIpn(bool $idempotencyCheck = false): Ipn
    {
        return $this->handleCallback($idempotencyCheck);
    }

    /**
     * Handle a callback with optional idempotency check.
     *
     * @param  bool  $idempotencyCheck  Whether to check for duplicate deliveries
     * @return Ipn The verified callback payload
     *
     * @throws IdempotencyException If duplicate delivery detected
     */
    public function handleCallback(bool $idempotencyCheck = true): Ipn
    {
        $ipnData = $this->getTransactionResult($this->getIpnResult());

        if ($ipnData instanceof Browser) {
            throw new InvalidPayloadException('Expected Ipn payload, got Browser payload.');
        }

        if ($idempotencyCheck && ! $this->shouldProcessIpn($ipnData)) {
            throw IdempotencyException::duplicateDelivery();
        }

        return $ipnData;
    }

    /**
     * Handle a browser redirect callback.
     *
     * @return Browser The verified browser callback payload
     */
    public function handleRedirect(): Browser
    {
        $browserData = $this->getTransactionResult($this->getBrowserResult());

        if ($browserData instanceof Ipn) {
            throw new InvalidPayloadException('Expected Browser payload, got Ipn payload.');
        }

        return $browserData;
    }

    /**
     * Get and verify a transaction result.
     *
     * @param  AbstractTransactionResult  $transactionResult  The transaction result to verify
     * @return Browser|Ipn The verified payload
     *
     * @throws InvalidSignatureException If signature validation fails
     */
    private function getTransactionResult(
        AbstractTransactionResult $transactionResult,
    ): Browser|Ipn {
        $payload = $transactionResult->getPayload();

        if ($payload === null) {
            throw new InvalidPayloadException('Failed to map payload from transaction result.');
        }

        /** @var Ipn|Browser $mappedPayload */
        $mappedPayload = $payload->getMapped();

        $resolvedProfile =
            $this->profile ??
            PaytabsResolver::resolveProfile($this->container, $mappedPayload) ??
            $this->container->make(Paytabs::class)->getProfile();

        $transactionResult->setProfile($resolvedProfile);

        $isGenuine = $transactionResult->isGenuine();

        if (! $isGenuine) {
            throw InvalidSignatureException::mismatch($resolvedProfile->getServerKeyPrefix());
        }

        return $mappedPayload;
    }

    /**
     * Dispatch a verified transaction result to the configured handler.
     *
     * @param  AbstractTransactionResult  $transactionResult  The verified transaction result
     * @param  Ipn  $mappedPayload  The mapped IPN payload
     */
    private function dispatchVerifiedTransactionResult(
        AbstractTransactionResult $transactionResult,
        Ipn $mappedPayload,
    ): void {
        $ipnHandler = PaytabsResolver::resolveIpnHandler($this->container);

        if ($ipnHandler === null) {
            Log::warning('No IPN handler configured. See "paytabs.ipn_handler" configuration value and the interface IpnHandlerInterface.');

            return;
        }

        $ipnHandler->handleIpn($transactionResult, $mappedPayload);
    }

    /**
     * Check if an IPN should be processed based on idempotency.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if this is the first delivery, false if duplicate
     */
    public function shouldProcessIpn(Ipn $ipn): bool
    {
        return
            $this->timeGuard($ipn)
            && $this->idempotencyGuard($ipn);
    }

    public function idempotencyGuard(Ipn $ipn): bool
    {
        if (! (bool) Config::get('paytabs.ipn_idempotency_enabled', true)) {
            return true;
        }

        $guard = $this->idempotencyGuard ?? $this->container->make(IpnIdempotencyGuardInterface::class);
        $isFirstDelivery = $guard->acquire($ipn);

        if (! $isFirstDelivery) {
            Log::info('PayTabs IPN ignored as duplicate delivery.', [
                'profile_id' => $ipn->profile_id,
                'tran_ref' => $ipn->tran_ref,
                'trace' => $ipn->ipn_trace,
                'response_status' => $ipn->payment_result->response_status,
            ]);
        }

        return $isFirstDelivery;
    }

    public function timeGuard(Ipn $ipn): bool
    {
        $timeGuard = (bool) Config::get('paytabs.ipn_time_guard_enabled', true);
        $timeGuardTtl = (int) Config::get('paytabs.ipn_time_guard_ttl_seconds', 3600);

        if (! $timeGuard) {
            return true;
        }

        $ipnTime = $ipn->payment_result->transaction_time;
        if (Carbon::now()->subSeconds($timeGuardTtl)->gt($ipnTime)) {
            Log::warning('Old IPN received', [
                'tran_ref' => $ipn->tran_ref,
                'ipn_time' => $ipnTime,
            ]);

            return false;
        }

        return true;
    }
}
