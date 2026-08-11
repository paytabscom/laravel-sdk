<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit;

use Paytabs\Laravel\Services\CacheIpnIdempotencyGuard;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

final class IdempotencyKeyTest extends TestCase
{
    public function test_key_is_driver_safe(): void
    {
        // Hashing keeps the key within the 250 byte Memcached limit whatever the gateway sends.
        $key = $this->keyFor(['tran_ref' => str_repeat('T', 512)]);

        $this->assertDoesNotMatchRegularExpression('/\s/', $key);
        $this->assertLessThanOrEqual(250, strlen($key));
    }

    public function test_key_is_stable_for_the_same_delivery(): void
    {
        $this->assertSame($this->keyFor(), $this->keyFor());
    }

    public function test_key_differs_per_transaction(): void
    {
        $this->assertNotSame(
            $this->keyFor(['tran_ref' => 'TST2000000000001']),
            $this->keyFor(['tran_ref' => 'TST2000000000002']),
        );
    }

    public function test_key_building_tolerates_a_missing_payment_result(): void
    {
        $ipn = $this->mapPayload(['tran_ref' => 'TST2000000000009']);
        unset($ipn->payment_result);

        $guard = $this->app->make(CacheIpnIdempotencyGuard::class);

        $this->assertTrue($guard->acquire($ipn));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function keyFor(array $overrides = []): string
    {
        $guard = $this->app->make(CacheIpnIdempotencyGuard::class);

        $method = new \ReflectionMethod($guard, 'buildKey');

        return $method->invoke($guard, $this->mapPayload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mapPayload(array $overrides = []): Ipn
    {
        $this->app['config']->set('paytabs.ipn_idempotency_enabled', false);

        $result = $this->processorFor($this->signedIpnRequest($overrides))->handleCallback(false);

        $this->app['config']->set('paytabs.ipn_idempotency_enabled', true);

        $this->assertInstanceOf(Ipn::class, $result->payload);

        return $result->payload;
    }
}
