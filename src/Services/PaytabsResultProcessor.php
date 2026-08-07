<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Exceptions\IdempotencyException;
use Paytabs\Laravel\Exceptions\InvalidPayloadException;
use Paytabs\Laravel\Exceptions\IpnProcessingException;
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
     * @param  Request|null  $request  Optional request, resolved from the container when omitted
     */
    public function __construct(
        private readonly Container $container,
        public readonly ?Profile $profile = null,
        private readonly ?IpnIdempotencyGuardInterface $idempotencyGuard = null,
        private readonly ?Request $request = null,
    ) {}

    private function request(): Request
    {
        return $this->request ?? $this->container->make(Request::class);
    }

    private function getIpnResult(): AbstractTransactionResult
    {
        if ($this->resultIpn === null) {
            $request = $this->request();

            // Read from the framework request, not php://input, so this works under Octane.
            $this->resultIpn = Callback::initWith(
                $request->getContent(),
                $this->flattenHeaders($request),
            );
        }

        return $this->resultIpn;
    }

    private function getBrowserResult(): AbstractTransactionResult
    {
        if ($this->resultBrowser === null) {
            $this->resultBrowser = BrowserAsPost::initWith($this->request()->request->all());
        }

        return $this->resultBrowser;
    }

    /**
     * Reduce Symfony's multi-value header bag to the single-value shape the SDK expects.
     *
     * @return array<string, string>
     */
    private function flattenHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    /**
     * Dispatch an IPN from the current request.
     *
     * @return IpnOutcome The outcome of the IPN dispatch
     */
    public function dispatchIpn(): IpnOutcome
    {
        $ipnOutcome = IpnOutcome::HandlerFailed;

        try {
            $ipnData = $this->handleIpn($ipnOutcome, true);
        } catch (IpnProcessingException $e) {
            Log::error('PayTabs IPN verification failed.', [
                'outcome' => $ipnOutcome->name,
                'exception' => $e,
            ]);

            return $ipnOutcome;
        } catch (Throwable $e) {
            Log::error('PayTabs IPN verification failed unexpectedly.', [
                'exception' => $e,
            ]);

            return IpnOutcome::HandlerFailed;
        }

        // Dispatched separately so a handler throwing IpnProcessingException is never reported as Processed.
        return $this->runIpnHandler($ipnData);
    }

    /**
     * Run the configured handler for a verified IPN.
     *
     * @param  Ipn  $ipnData  The verified IPN payload
     * @return IpnOutcome Processed on success, HandlerFailed if the handler threw
     */
    private function runIpnHandler(Ipn $ipnData): IpnOutcome
    {
        try {
            $this->dispatchVerifiedTransactionResult($this->getIpnResult(), $ipnData);
        } catch (Throwable $e) {
            Log::error('PayTabs IPN handler execution failed.', [
                'tran_ref' => $ipnData->tran_ref ?? null,
                'exception' => $e,
            ]);

            $this->idempotencyRelease($ipnData);

            return IpnOutcome::HandlerFailed;
        }

        return IpnOutcome::Processed;
    }

    /**
     * Handle an IPN callback with idempotency check.
     *
     * @param  IpnOutcome  $ipnOutcome  Receives the outcome by reference, set on both success and failure
     * @param  bool  $idempotencyCheck  Whether to apply the time and duplicate guards
     * @return Ipn The verified IPN payload
     *
     * @throws IpnProcessingException If verification or a guard rejects the delivery
     * @throws InvalidPayloadException If the payload is not an IPN payload
     */
    public function handleIpn(IpnOutcome &$ipnOutcome, bool $idempotencyCheck = true): Ipn
    {
        return $this->handleCallback($ipnOutcome, $idempotencyCheck);
    }

    /**
     * Handle a callback with optional idempotency check.
     *
     * @param  IpnOutcome  $ipnOutcome  Receives the outcome by reference, set on both success and failure
     * @param  bool  $idempotencyCheck  Whether to apply the time and duplicate guards
     * @return Ipn The verified callback payload
     *
     * @throws IpnProcessingException If verification or a guard rejects the delivery
     * @throws InvalidPayloadException If the payload is not an IPN payload
     */
    public function handleCallback(
        IpnOutcome &$ipnOutcome = IpnOutcome::HandlerFailed,
        bool $idempotencyCheck = true
    ): Ipn {
        $ipnOutcome = IpnOutcome::HandlerFailed;

        try {
            $ipnData = $this->getTransactionResult($this->getIpnResult());
        } catch (InvalidSignatureException $e) {
            $ipnOutcome = IpnOutcome::InvalidSignature;

            throw new IpnProcessingException('PayTabs callback rejected: invalid signature.', 0, $e);
        } catch (InvalidPayloadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new IpnProcessingException('PayTabs callback verification failed.', 0, $e);
        }

        if ($ipnData instanceof Browser) {
            throw new InvalidPayloadException('Expected Ipn payload, got Browser payload.');
        }

        if ($idempotencyCheck) {
            if (! $this->timeGuard($ipnData)) {
                $ipnOutcome = IpnOutcome::Stale;

                throw IpnProcessingException::forIpn($ipnData, 'Stale delivery');
            }

            if (! $this->idempotencyGuard($ipnData)) {
                $ipnOutcome = IpnOutcome::Duplicate;

                throw IdempotencyException::forIpn($ipnData, 'Duplicate delivery');
            }
        }

        $ipnOutcome = IpnOutcome::Processed;

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
            Log::warning('PayTabs callback rejected: invalid signature.', [
                'tran_ref' => $mappedPayload->tran_ref ?? null,
                // Non-reversible, so the public endpoint cannot be used to harvest key material from logs.
                'server_key_fingerprint' => substr(hash('sha256', $resolvedProfile->getServerKeyPrefix()), 0, 8),
            ]);

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
                'profile_id' => $ipn->profile_id ?? null,
                'tran_ref' => $ipn->tran_ref ?? null,
                'trace' => $ipn->ipn_trace ?? null,
                'response_status' => $ipn->payment_result->response_status ?? null,
            ]);
        }

        return $isFirstDelivery;
    }

    /**
     * Release a previously acquired idempotency lock so the delivery can be retried.
     *
     * @param  Ipn  $ipn  The IPN payload whose lock should be released
     */
    public function idempotencyRelease(Ipn $ipn): void
    {
        if (! (bool) Config::get('paytabs.ipn_idempotency_enabled', true)) {
            return;
        }

        try {
            $guard = $this->idempotencyGuard ?? $this->container->make(IpnIdempotencyGuardInterface::class);
            $guard->release($ipn);
        } catch (Throwable $e) {
            // Never mask the original handler failure that triggered the release.
            Log::error('PayTabs IPN idempotency release failed.', [
                'tran_ref' => $ipn->tran_ref ?? null,
                'exception' => $e,
            ]);

            return;
        }

        Log::info('PayTabs IPN idempotency lock released.', [
            'tran_ref' => $ipn->tran_ref ?? null,
            'trace' => $ipn->ipn_trace ?? null,
        ]);
    }

    /**
     * Reject IPNs whose transaction time falls outside the accepted window.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if the transaction time is within the accepted window
     */
    public function timeGuard(Ipn $ipn): bool
    {
        if (! (bool) Config::get('paytabs.ipn_time_guard_enabled', true)) {
            return true;
        }

        $rejection = $this->findTimeGuardRejection($ipn);

        if ($rejection === null) {
            return true;
        }

        Log::warning('PayTabs IPN rejected: '.$rejection, [
            'tran_ref' => $ipn->tran_ref ?? null,
            'transaction_time' => $ipn->payment_result->transaction_time ?? null,
        ]);

        return false;
    }

    /**
     * Evaluate the payload's transaction time against the accepted window.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return string|null Rejection reason, or null when the transaction time is acceptable
     */
    private function findTimeGuardRejection(Ipn $ipn): ?string
    {
        $rawTime = $ipn->payment_result->transaction_time ?? null;

        if (! is_string($rawTime) || trim($rawTime) === '') {
            return 'missing transaction time';
        }

        // PayTabs always sends ISO 8601 in UTC, so the offset in the string decides the instant.
        try {
            $ipnTime = Carbon::parse($rawTime);
        } catch (Throwable) {
            return 'unparsable transaction time';
        }

        $now = Carbon::now();
        $ttlSeconds = max(1, (int) Config::get('paytabs.ipn_time_guard_ttl_seconds', 3600));
        $skewSeconds = max(0, (int) Config::get('paytabs.ipn_time_guard_future_skew_seconds', 300));

        return match (true) {
            $ipnTime->lessThan($now->copy()->subSeconds($ttlSeconds)) => 'transaction time is too old',
            $ipnTime->greaterThan($now->copy()->addSeconds($skewSeconds)) => 'transaction time is in the future',
            default => null,
        };
    }
}
