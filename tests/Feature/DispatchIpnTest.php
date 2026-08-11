<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Feature;

use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Services\CacheIpnIdempotencyGuard;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;
use RuntimeException;

final class DispatchIpnTest extends TestCase
{
    public function test_a_valid_ipn_is_processed(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', RecordingHandler::class);

        $outcome = $this->processorFor($this->signedIpnRequest())->dispatchIpn();

        $this->assertSame(IpnOutcome::Processed, $outcome);
        $this->assertCount(1, RecordingHandler::$received);
    }

    public function test_handler_failure_releases_the_idempotency_lock(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', ThrowsRuntimeExceptionHandler::class);

        $this->processorFor($this->signedIpnRequest())->dispatchIpn();

        // The lock must be free, otherwise a PayTabs retry can never be processed.
        $guard = $this->app->make(CacheIpnIdempotencyGuard::class);
        $this->assertTrue($guard->acquire($this->mappedIpn()));
    }

    public function test_generic_handler_failure_releases_the_idempotency_lock(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', ThrowsRuntimeExceptionHandler::class);

        $outcome = $this->processorFor($this->signedIpnRequest())->dispatchIpn();

        $this->assertSame(IpnOutcome::HandlerFailed, $outcome);

        $guard = $this->app->make(CacheIpnIdempotencyGuard::class);
        $this->assertTrue($guard->acquire($this->mappedIpn()));
    }

    public function test_invalid_signature_is_reported_as_invalid_signature(): void
    {
        $outcome = $this->processorFor($this->signedIpnRequest([], 'deadbeef'))->dispatchIpn();

        $this->assertSame(IpnOutcome::InvalidSignature, $outcome);
    }

    public function test_duplicate_delivery_is_reported_as_duplicate(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', RecordingHandler::class);

        $this->assertSame(IpnOutcome::Processed, $this->processorFor($this->signedIpnRequest())->dispatchIpn());
        $this->assertSame(IpnOutcome::Duplicate, $this->processorFor($this->signedIpnRequest())->dispatchIpn());
    }

    public function test_stale_delivery_is_reported_as_stale(): void
    {
        $request = $this->signedIpnRequest([
            'payment_result' => ['transaction_time' => gmdate(self::TRANSACTION_TIME_FORMAT, time() - 7200)],
        ]);

        $this->assertSame(IpnOutcome::Stale, $this->processorFor($request)->dispatchIpn());
    }

    public function test_handle_callback_reports_processed_only_after_the_guards_pass(): void
    {
        $result = $this->processorFor($this->signedIpnRequest())->handleCallback(true);

        $this->assertSame(IpnOutcome::Processed, $result->outcome);
        $this->assertInstanceOf(Ipn::class, $result->payload);
    }

    public function test_handle_callback_returns_a_rejection_instead_of_throwing(): void
    {
        $result = $this->processorFor($this->signedIpnRequest([], 'deadbeef'))->handleCallback(true);

        $this->assertSame(IpnOutcome::InvalidSignature, $result->outcome);
        $this->assertNull($result->payload);
        $this->assertInstanceOf(InvalidSignatureException::class, $result->cause);
    }

    public function test_a_malformed_payload_is_reported_as_invalid_payload(): void
    {
        $request = $this->ipnRequest('not json at all', 'deadbeef');

        $result = $this->processorFor($request)->handleCallback(true);

        $this->assertSame(IpnOutcome::InvalidPayload, $result->outcome);
        // PayTabs only abandons a delivery on 403, 404 or 405, and a malformed body never becomes valid.
        $this->assertSame(403, $result->toResponse()->getStatusCode());
    }

    public function test_a_malformed_payload_does_not_return_a_retryable_status(): void
    {
        $request = $this->ipnRequest('not json at all', 'deadbeef');

        $this->assertSame(
            IpnOutcome::InvalidPayload,
            $this->processorFor($request)->dispatchIpn(),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingHandler::$received = [];
    }

    private function mappedIpn(): Ipn
    {
        // Idempotency is disabled here so resolving the payload does not re-acquire the lock.
        $this->app['config']->set('paytabs.ipn_idempotency_enabled', false);
        $result = $this->processorFor($this->signedIpnRequest())->handleCallback(false);
        $this->app['config']->set('paytabs.ipn_idempotency_enabled', true);

        $this->assertInstanceOf(Ipn::class, $result->payload);

        return $result->payload;
    }
}

final class RecordingHandler implements IpnHandlerInterface
{
    /** @var array<int, Ipn> */
    public static array $received = [];

    public function handleIpn(AbstractTransactionResult $transactionResult, Ipn $mappedPayload): void
    {
        self::$received[] = $mappedPayload;
    }
}

final class ThrowsRuntimeExceptionHandler implements IpnHandlerInterface
{
    public function handleIpn(AbstractTransactionResult $transactionResult, Ipn $mappedPayload): void
    {
        throw new RuntimeException('Database unavailable.');
    }
}
