<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class IpnOutcomeTest extends TestCase
{
    public function test_every_case_maps_to_a_response(): void
    {
        foreach (IpnOutcome::cases() as $case) {
            $this->assertInstanceOf(JsonResponse::class, $case->toResponse());
        }
    }

    #[DataProvider('statusCodes')]
    public function test_status_codes(IpnOutcome $outcome, int $expected): void
    {
        $this->assertSame($expected, $outcome->toResponse()->getStatusCode());
    }

    #[DataProvider('responseBodies')]
    public function test_response_bodies(IpnOutcome $outcome, array $expected): void
    {
        $this->assertSame($expected, $outcome->toResponse()->getData(true));
    }

    public function test_handler_failure_can_be_acknowledged(): void
    {
        $this->app['config']->set('paytabs.ack_on_handler_exception', true);

        $response = IpnOutcome::HandlerFailed->toResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'received'], $response->getData(true));
    }

    /**
     * PayTabs stops on a 2xx, and of the failure statuses it abandons only 403, 404 and 405.
     * Anything else is retried, so each outcome must sit on the correct side of that line.
     */
    #[DataProvider('retryExpectations')]
    public function test_outcomes_land_on_the_intended_side_of_the_retry_rule(IpnOutcome $outcome, bool $shouldRetry): void
    {
        $this->assertSame($shouldRetry, $this->paytabsWouldRetry($outcome));
    }

    public function test_acknowledging_a_handler_failure_stops_the_retries(): void
    {
        $this->assertTrue($this->paytabsWouldRetry(IpnOutcome::HandlerFailed));

        $this->app['config']->set('paytabs.ack_on_handler_exception', true);

        $this->assertFalse($this->paytabsWouldRetry(IpnOutcome::HandlerFailed));
    }

    private function paytabsWouldRetry(IpnOutcome $outcome): bool
    {
        $status = $outcome->toResponse()->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return false;
        }

        return ! in_array($status, [403, 404, 405], true);
    }

    /**
     * @return array<string, array{IpnOutcome, bool}>
     */
    public static function retryExpectations(): array
    {
        return [
            // Acknowledged: a 2xx also stops delivery, and the work is already done.
            'processed' => [IpnOutcome::Processed, false],
            'duplicate' => [IpnOutcome::Duplicate, false],
            'stale' => [IpnOutcome::Stale, false],
            'disabled' => [IpnOutcome::Disabled, false],
            // Permanent rejections: retrying can never change the answer.
            'invalid signature' => [IpnOutcome::InvalidSignature, false],
            'invalid payload' => [IpnOutcome::InvalidPayload, false],
            // Transient: the handler may succeed on a later attempt.
            'handler failed' => [IpnOutcome::HandlerFailed, true],
        ];
    }

    /**
     * @return array<string, array{IpnOutcome, int}>
     */
    public static function statusCodes(): array
    {
        return [
            'processed' => [IpnOutcome::Processed, 200],
            'invalid signature' => [IpnOutcome::InvalidSignature, 403],
            'invalid payload' => [IpnOutcome::InvalidPayload, 403],
            'duplicate' => [IpnOutcome::Duplicate, 200],
            'stale' => [IpnOutcome::Stale, 200],
            'disabled' => [IpnOutcome::Disabled, 200],
            'handler failed' => [IpnOutcome::HandlerFailed, 500],
        ];
    }

    /**
     * @return array<string, array{IpnOutcome, array<string, string>}>
     */
    public static function responseBodies(): array
    {
        return [
            'processed' => [IpnOutcome::Processed, ['status' => 'received']],
            'invalid signature' => [IpnOutcome::InvalidSignature, ['status' => 'error', 'message' => 'Invalid Signature']],
            'invalid payload' => [IpnOutcome::InvalidPayload, ['status' => 'error', 'message' => 'Invalid Payload']],
            'duplicate' => [IpnOutcome::Duplicate, ['status' => 'ignored', 'message' => 'Duplicate IPN']],
            'stale' => [IpnOutcome::Stale, ['status' => 'ignored', 'message' => 'Stale IPN']],
            'disabled' => [IpnOutcome::Disabled, ['status' => 'ignored', 'message' => 'IPN Handling Disabled']],
            'handler failed' => [IpnOutcome::HandlerFailed, ['status' => 'error', 'message' => 'IPN Handler Failed']],
        ];
    }
}
