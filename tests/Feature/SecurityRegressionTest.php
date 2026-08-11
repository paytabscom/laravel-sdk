<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Exceptions\InvalidConfigurationException;
use Paytabs\Laravel\Services\PaytabsResolver;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;

/**
 * Pins two fixes an attacker or a misconfiguration could otherwise exploit silently.
 */
final class SecurityRegressionTest extends TestCase
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    public function test_no_log_entry_contains_server_key_material(): void
    {
        $this->captureLogs();

        // An unauthenticated request must not be able to force key material into the logs.
        $this->processorFor($this->signedIpnRequest([], 'deadbeef'))->dispatchIpn();

        $this->assertNotSame([], $this->logRecords, 'Expected the rejection to be logged.');

        $serialised = json_encode($this->logRecords, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SERVER_KEY, $serialised);
        // The first ten characters were the prefix that used to leak.
        $this->assertStringNotContainsString(substr(self::SERVER_KEY, 0, 10), $serialised);
        $this->assertStringNotContainsString(substr(self::SERVER_KEY, 0, 4), $serialised);
    }

    public function test_no_exception_message_contains_server_key_material(): void
    {
        $result = $this->processorFor($this->signedIpnRequest([], 'deadbeef'))->handleCallback(true);

        $this->assertSame(IpnOutcome::InvalidSignature, $result->outcome);
        $this->assertNotNull($result->cause);
        $this->assertStringNotContainsString(substr(self::SERVER_KEY, 0, 4), $result->cause->getMessage());
        $this->assertStringNotContainsString(substr(self::SERVER_KEY, 0, 4), (string) $result->reason);
    }

    public function test_the_invalid_signature_log_carries_an_irreversible_fingerprint(): void
    {
        $this->captureLogs();

        $this->processorFor($this->signedIpnRequest([], 'deadbeef'))->dispatchIpn();

        $fingerprints = array_filter(
            array_column($this->logRecords, 'context'),
            fn ($c) => isset($c['server_key_fingerprint']),
        );

        $this->assertNotSame([], $fingerprints, 'Expected a fingerprint so profiles remain distinguishable in logs.');

        foreach ($fingerprints as $context) {
            $this->assertSame(8, strlen((string) $context['server_key_fingerprint']));
        }
    }

    public function test_a_missing_handler_is_never_reported_as_processed(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', null);

        // Acknowledging here would tell PayTabs the payment was handled and stop all retries.
        $this->assertSame(
            IpnOutcome::HandlerFailed,
            $this->processorFor($this->signedIpnRequest())->dispatchIpn(),
        );
    }

    public function test_a_missing_handler_returns_a_retryable_status_over_http(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', null);

        $this->postSignedIpn()->assertStatus(500);
    }

    public function test_a_missing_handler_releases_the_idempotency_lock(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', null);

        $this->processorFor($this->signedIpnRequest())->dispatchIpn();

        // Without the release, every PayTabs retry would be swallowed as a duplicate.
        $this->app['config']->set('paytabs.ipn_handler', LockProbeHandler::class);
        LockProbeHandler::$received = [];

        $this->assertSame(
            IpnOutcome::Processed,
            $this->processorFor($this->signedIpnRequest())->dispatchIpn(),
        );
        $this->assertCount(1, LockProbeHandler::$received);
    }

    public function test_resolving_a_missing_handler_throws(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', null);

        $this->expectException(InvalidConfigurationException::class);

        PaytabsResolver::resolveIpnHandler($this->app);
    }

    public function test_a_misconfigured_handler_is_not_logged_as_an_execution_failure(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', null);
        $this->captureLogs();

        $this->processorFor($this->signedIpnRequest())->dispatchIpn();

        $messages = array_column($this->logRecords, 'message');

        // "execution failed" would imply the merchant's handler ran and threw.
        $this->assertContains('PayTabs IPN handler is not configured correctly.', $messages);
        $this->assertNotContains('PayTabs IPN handler execution failed.', $messages);
    }

    public function test_a_throwing_handler_is_still_logged_as_an_execution_failure(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', ExplodingHandler::class);
        $this->captureLogs();

        $this->processorFor($this->signedIpnRequest())->dispatchIpn();

        $messages = array_column($this->logRecords, 'message');

        $this->assertContains('PayTabs IPN handler execution failed.', $messages);
        $this->assertNotContains('PayTabs IPN handler is not configured correctly.', $messages);
    }

    private function captureLogs(): void
    {
        $this->logRecords = [];

        Log::listen(function ($message): void {
            $this->logRecords[] = [
                'level' => $message->level,
                'message' => $message->message,
                'context' => $message->context,
            ];
        });
    }
}

final class LockProbeHandler implements IpnHandlerInterface
{
    /** @var array<int, Ipn> */
    public static array $received = [];

    public function handleIpn(AbstractTransactionResult $transactionResult, Ipn $mappedPayload): void
    {
        self::$received[] = $mappedPayload;
    }
}

final class ExplodingHandler implements IpnHandlerInterface
{
    public function handleIpn(AbstractTransactionResult $transactionResult, Ipn $mappedPayload): void
    {
        throw new \RuntimeException('Order lookup failed.');
    }
}
