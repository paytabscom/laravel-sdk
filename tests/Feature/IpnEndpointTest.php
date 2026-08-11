<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;

/**
 * Exercises the packaged route and controller over the real HTTP stack.
 */
final class IpnEndpointTest extends TestCase
{
    public function test_the_package_registers_the_ipn_route(): void
    {
        $route = Route::getRoutes()->getByName('paytabs.ipn');

        $this->assertNotNull($route);
        $this->assertSame('paytabs/ipn', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_a_signed_ipn_returns_200_received(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', EndpointRecordingHandler::class);

        $this->postSignedIpn()
            ->assertOk()
            ->assertExactJson(['status' => 'received']);

        $this->assertCount(1, EndpointRecordingHandler::$received);
    }

    public function test_an_invalid_signature_returns_403(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', EndpointRecordingHandler::class);

        $this->postSignedIpn([], 'deadbeef')
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid Signature']);

        $this->assertSame([], EndpointRecordingHandler::$received);
    }

    public function test_a_malformed_body_returns_a_non_retryable_403(): void
    {
        $this->postIpnBody('not json at all', 'deadbeef')
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid Payload']);
    }

    /**
     * PayTabs abandons a delivery only on 403, 404 or 405, so every permanent
     * rejection must answer one of those. Anything else triggers endless retries.
     */
    public function test_permanent_rejections_use_a_status_paytabs_stops_retrying(): void
    {
        $nonRetryable = [403, 404, 405];

        $this->assertContains(
            $this->postSignedIpn([], 'deadbeef')->getStatusCode(),
            $nonRetryable,
        );

        $this->assertContains(
            $this->postIpnBody('not json at all', 'deadbeef')->getStatusCode(),
            $nonRetryable,
        );
    }

    public function test_a_duplicate_delivery_is_acknowledged_without_reprocessing(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', EndpointRecordingHandler::class);

        $this->postSignedIpn()->assertOk();

        // A 2xx is required, otherwise PayTabs keeps retrying a delivery already handled.
        $this->postSignedIpn()
            ->assertOk()
            ->assertExactJson(['status' => 'ignored', 'message' => 'Duplicate IPN']);

        $this->assertCount(1, EndpointRecordingHandler::$received);
    }

    public function test_a_stale_delivery_is_acknowledged_without_reprocessing(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', EndpointRecordingHandler::class);

        $this->postSignedIpn([
            'payment_result' => ['transaction_time' => gmdate(self::TRANSACTION_TIME_FORMAT, time() - 7200)],
        ])
            ->assertOk()
            ->assertExactJson(['status' => 'ignored', 'message' => 'Stale IPN']);

        $this->assertSame([], EndpointRecordingHandler::$received);
    }

    public function test_the_endpoint_can_be_disabled(): void
    {
        $this->app['config']->set('paytabs.ipn_enabled', false);
        $this->app['config']->set('paytabs.ipn_handler', EndpointRecordingHandler::class);

        $this->postSignedIpn()
            ->assertOk()
            ->assertExactJson(['status' => 'ignored', 'message' => 'IPN Handling Disabled']);

        $this->assertSame([], EndpointRecordingHandler::$received);
    }

    public function test_the_endpoint_rejects_get_requests(): void
    {
        $this->get('/paytabs/ipn')->assertMethodNotAllowed();
    }

    public function test_the_route_is_rate_limited_by_default(): void
    {
        $middleware = Route::getRoutes()->getByName('paytabs.ipn')?->gatherMiddleware() ?? [];

        // Laravel 11+ leaves the api group unthrottled unless the app calls ->throttleApi(),
        // so this public endpoint ships with an explicit limit.
        $this->assertNotEmpty(
            array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle')),
            'Expected a throttle middleware on the IPN route.',
        );
    }

    public function test_the_endpoint_is_not_behind_csrf_verification(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', EndpointRecordingHandler::class);

        // PayTabs cannot supply a CSRF token, so a 419 here would break every notification.
        $this->postSignedIpn()->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();

        EndpointRecordingHandler::$received = [];
    }
}

final class EndpointRecordingHandler implements IpnHandlerInterface
{
    /** @var array<int, Ipn> */
    public static array $received = [];

    public function handleIpn(AbstractTransactionResult $transactionResult, Ipn $mappedPayload): void
    {
        self::$received[] = $mappedPayload;
    }
}
