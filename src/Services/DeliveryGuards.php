<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Throwable;

/**
 * Freshness and duplicate-delivery guards applied to a verified IPN.
 */
class DeliveryGuards
{
    /**
     * Create a new delivery guard set.
     *
     * @param  Container  $container  The Laravel container
     * @param  IpnIdempotencyGuardInterface|null  $idempotencyGuard  Optional guard, resolved from the container when omitted
     */
    public function __construct(
        private readonly Container $container,
        private readonly ?IpnIdempotencyGuardInterface $idempotencyGuard = null,
    ) {}

    /**
     * Check both guards for a delivery.
     *
     * Note: on success this acquires the idempotency lock. Call it at most once per
     * delivery, and call release() if your own processing then fails.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if this is the first delivery, false if stale or duplicate
     */
    public function shouldProcess(Ipn $ipn): bool
    {
        return $this->isFresh($ipn) && $this->acquire($ipn);
    }

    /**
     * Reject IPNs whose transaction time falls outside the accepted window.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if the transaction time is within the accepted window
     */
    public function isFresh(Ipn $ipn): bool
    {
        if (! (bool) Config::get('paytabs.ipn_time_guard_enabled', true)) {
            return true;
        }

        $rejection = $this->findTimeRejection($ipn);

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
     * Acquire processing ownership for a delivery.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return bool True if this is the first delivery, false if duplicate
     */
    public function acquire(Ipn $ipn): bool
    {
        if (! (bool) Config::get('paytabs.ipn_idempotency_enabled', true)) {
            return true;
        }

        $isFirstDelivery = $this->guard()->acquire($ipn);

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
    public function release(Ipn $ipn): void
    {
        if (! (bool) Config::get('paytabs.ipn_idempotency_enabled', true)) {
            return;
        }

        try {
            $this->guard()->release($ipn);
        } catch (Throwable $e) {
            // Never mask the original handler failure that triggered the release.
            Log::error('PayTabs IPN idempotency release failed.', [
                'tran_ref' => $ipn->tran_ref ?? null,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('PayTabs IPN idempotency lock released.', [
            'tran_ref' => $ipn->tran_ref ?? null,
            'trace' => $ipn->ipn_trace ?? null,
        ]);
    }

    private function guard(): IpnIdempotencyGuardInterface
    {
        return $this->idempotencyGuard ?? $this->container->make(IpnIdempotencyGuardInterface::class);
    }

    /**
     * Evaluate the payload's transaction time against the accepted window.
     *
     * @param  Ipn  $ipn  The IPN payload to check
     * @return string|null Rejection reason, or null when the transaction time is acceptable
     */
    private function findTimeRejection(Ipn $ipn): ?string
    {
        $parsed = $this->parseTransactionTime($ipn->payment_result->transaction_time ?? null);

        if (is_string($parsed)) {
            return $parsed;
        }

        $now = Carbon::now();
        $ttlSeconds = max(1, (int) Config::get('paytabs.ipn_time_guard_ttl_seconds', 3600));
        $skewSeconds = max(0, (int) Config::get('paytabs.ipn_time_guard_future_skew_seconds', 300));

        return match (true) {
            $parsed->lessThan($now->copy()->subSeconds($ttlSeconds)) => 'transaction time is too old',
            $parsed->greaterThan($now->copy()->addSeconds($skewSeconds)) => 'transaction time is in the future',
            default => null,
        };
    }

    /**
     * Parse the wire timestamp, or describe why it could not be parsed.
     *
     * @param  mixed  $rawTime  The raw transaction_time value from the payload
     * @return Carbon|string The parsed instant, or a rejection reason
     */
    private function parseTransactionTime(mixed $rawTime): Carbon|string
    {
        if (! is_string($rawTime) || trim($rawTime) === '') {
            return 'missing transaction time';
        }

        // PayTabs always sends RFC3339 in UTC, sample: 2026-08-10T06:30:37Z
        try {
            $parsed = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $rawTime, 'UTC');
        } catch (Throwable) {
            return 'unparsable transaction time';
        }

        return $parsed instanceof Carbon ? $parsed : 'invalid transaction time format';
    }
}
