<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit;

use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use PHPUnit\Framework\Attributes\DataProvider;

final class TimeGuardTest extends TestCase
{
    #[DataProvider('transactionTimes')]
    public function test_time_guard_window(string $transactionTime, bool $expected): void
    {
        $this->app['config']->set('paytabs.ipn_time_guard_ttl_seconds', 3600);
        $this->app['config']->set('paytabs.ipn_time_guard_future_skew_seconds', 300);

        $processor = $this->processorFor($this->signedIpnRequest());

        $this->assertSame($expected, $processor->timeGuard($this->ipnWithTime($transactionTime)));
    }

    public function test_app_timezone_does_not_shift_the_window(): void
    {
        $ipn = $this->ipnWithTime(gmdate(self::TRANSACTION_TIME_FORMAT));

        $this->app['config']->set('app.timezone', 'Asia/Tokyo');
        date_default_timezone_set('Asia/Tokyo');

        try {
            // The wire format is always UTC, so a 9 hour app offset must not age the payload out.
            $this->assertTrue($this->processorFor($this->signedIpnRequest())->timeGuard($ipn));
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_missing_transaction_time_is_rejected(): void
    {
        $ipn = $this->ipnWithTime(gmdate(self::TRANSACTION_TIME_FORMAT));
        unset($ipn->payment_result);

        $this->assertFalse($this->processorFor($this->signedIpnRequest())->timeGuard($ipn));
    }

    public function test_unparsable_transaction_time_is_rejected(): void
    {
        $this->assertFalse(
            $this->processorFor($this->signedIpnRequest())->timeGuard($this->ipnWithTime('not-a-date'))
        );
    }

    public function test_guard_can_be_disabled(): void
    {
        $this->app['config']->set('paytabs.ipn_time_guard_enabled', false);

        $this->assertTrue(
            $this->processorFor($this->signedIpnRequest())->timeGuard($this->ipnWithTime('not-a-date'))
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function transactionTimes(): array
    {
        $format = self::TRANSACTION_TIME_FORMAT;

        return [
            'now in utc' => [gmdate($format), true],
            'within ttl' => [gmdate($format, time() - 1800), true],
            'older than ttl' => [gmdate($format, time() - 7200), false],
            'far in the future' => [gmdate($format, time() + 7200), false],
            'within future skew' => [gmdate($format, time() + 60), true],
            'numeric offset instead of z' => [gmdate('c'), false],
        ];
    }

    private function ipnWithTime(string $transactionTime): Ipn
    {
        $this->app['config']->set('paytabs.ipn_idempotency_enabled', false);

        $result = $this->processorFor(
            $this->signedIpnRequest(['payment_result' => ['transaction_time' => $transactionTime]])
        )->handleCallback(false);

        $this->app['config']->set('paytabs.ipn_idempotency_enabled', true);

        $this->assertInstanceOf(Ipn::class, $result->payload);

        return $result->payload;
    }
}
