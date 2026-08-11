<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit;

use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Laravel\Exceptions\InvalidConfigurationException;
use Paytabs\Laravel\Services\CacheIpnIdempotencyGuard;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

final class IdempotencyGuardTest extends TestCase
{
    public function test_the_first_delivery_acquires_and_a_repeat_does_not(): void
    {
        $ipn = $this->fixedIpn();
        $guard = $this->guard();

        $this->assertTrue($guard->acquire($ipn));
        $this->assertFalse($guard->acquire($ipn));
    }

    public function test_release_allows_the_same_delivery_to_be_acquired_again(): void
    {
        // The same instance throughout, so the assertion cannot pass on a changed key.
        $ipn = $this->fixedIpn();
        $guard = $this->guard();

        $this->assertTrue($guard->acquire($ipn));
        $this->assertFalse($guard->acquire($ipn));

        $guard->release($ipn);

        $this->assertTrue($guard->acquire($ipn));
    }

    public function test_releasing_a_lock_that_was_never_acquired_is_harmless(): void
    {
        $guard = $this->guard();
        $ipn = $this->fixedIpn();

        $guard->release($ipn);

        $this->assertTrue($guard->acquire($ipn));
    }

    public function test_distinct_transactions_do_not_share_a_lock(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->acquire($this->fixedIpn('TST2000000000001')));
        $this->assertTrue($guard->acquire($this->fixedIpn('TST2000000000002')));
    }

    public function test_the_lock_expires_after_the_configured_ttl(): void
    {
        $this->app['config']->set('paytabs.ipn_idempotency_ttl_seconds', 60);

        $ipn = $this->fixedIpn();
        $guard = $this->guard();

        $this->assertTrue($guard->acquire($ipn));

        $this->travel(61)->seconds();

        // PayTabs may legitimately resend after the window, so the lock must not be permanent.
        $this->assertTrue($guard->acquire($ipn));
    }

    public function test_a_named_cache_store_is_honoured(): void
    {
        $this->app['config']->set('cache.stores.paytabs_locks', ['driver' => 'array']);
        $this->app['config']->set('paytabs.ipn_idempotency_cache_store', 'paytabs_locks');

        $ipn = $this->fixedIpn();

        $this->assertTrue($this->guard()->acquire($ipn));
        $this->assertFalse($this->guard()->acquire($ipn));
    }

    public function test_a_null_cache_store_is_rejected(): void
    {
        $this->app['config']->set('cache.stores.void', ['driver' => 'null']);
        $this->app['config']->set('paytabs.ipn_idempotency_cache_store', 'void');

        // A null store reports every delivery as duplicate, silently discarding all IPNs.
        $this->expectException(InvalidConfigurationException::class);

        $this->guard()->acquire($this->fixedIpn());
    }

    public function test_the_key_prefix_is_configurable(): void
    {
        $this->app['config']->set('paytabs.ipn_idempotency_key_prefix', 'tenant-a:ipn');

        $method = new \ReflectionMethod($this->guard(), 'buildKey');
        $key = $method->invoke($this->guard(), $this->fixedIpn());

        $this->assertStringStartsWith('tenant-a:ipn:', $key);
    }

    public function test_the_default_guard_is_bound_to_the_contract(): void
    {
        $this->assertInstanceOf(
            CacheIpnIdempotencyGuard::class,
            $this->app->make(IpnIdempotencyGuardInterface::class),
        );
    }

    /**
     * Build a payload with a pinned transaction time so the cache key is stable across calls.
     */
    private function fixedIpn(string $tranRef = 'TST2000000000001'): Ipn
    {
        $this->app['config']->set('paytabs.ipn_idempotency_enabled', false);

        $result = $this->processorFor($this->signedIpnRequest([
            'tran_ref' => $tranRef,
            'payment_result' => ['transaction_time' => '2026-08-10T06:30:37Z'],
        ]))->handleCallback(false);

        $this->app['config']->set('paytabs.ipn_idempotency_enabled', true);

        $this->assertInstanceOf(Ipn::class, $result->payload);

        return $result->payload;
    }

    private function guard(): CacheIpnIdempotencyGuard
    {
        return $this->app->make(CacheIpnIdempotencyGuard::class);
    }
}
