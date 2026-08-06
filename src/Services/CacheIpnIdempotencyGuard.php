<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class CacheIpnIdempotencyGuard implements IpnIdempotencyGuardInterface
{
    /**
     * Create a new cache-based idempotency guard.
     *
     * @param  CacheFactory  $cacheFactory  The Laravel cache factory
     */
    public function __construct(
        private readonly CacheFactory $cacheFactory,
    ) {}

    /**
     * Acquire processing ownership for an IPN payload using cache.
     *
     * @param  Ipn  $payload  The IPN payload to check
     * @return bool True if this is the first delivery, false if duplicate
     */
    public function acquire(Ipn $payload): bool
    {
        $ttlSeconds = max(1, (int) Config::get('paytabs.ipn_idempotency_ttl_seconds', 180));

        return $this->store()->add(
            $this->buildKey($payload),
            true,
            $ttlSeconds,
        );
    }

    /**
     * Release processing ownership so the delivery can be retried.
     *
     * @param  Ipn  $payload  The IPN payload whose lock should be released
     */
    public function release(Ipn $payload): void
    {
        $this->store()->forget($this->buildKey($payload));
    }

    private function store(): Repository
    {
        $storeName = trim((string) Config::get('paytabs.ipn_idempotency_cache_store', ''));

        return $storeName === ''
            ? $this->cacheFactory->store()
            : $this->cacheFactory->store($storeName);
    }

    /**
     * Build a unique cache key for the IPN payload.
     *
     * @param  Ipn  $payload  The IPN payload
     * @return string The cache key
     */
    private function buildKey(Ipn $payload): string
    {
        $prefix = trim((string) Config::get('paytabs.ipn_idempotency_key_prefix', 'paytabs:ipn'));

        // Payload properties are typed and non-nullable, so ?? guards against unmapped fields.
        $composite = implode('|', [
            $payload->profile_id ?? '',
            $payload->tran_ref ?? '',
            $payload->tran_type ?? '',
            $payload->payment_result->transaction_time ?? '',
        ]);

        // Hashed to bound the key length, since tran_ref has no documented maximum.
        return $prefix.':'.hash('sha256', $composite);
    }
}
