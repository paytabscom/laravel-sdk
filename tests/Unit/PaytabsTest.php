<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit;

use Paytabs\Laravel\Paytabs;
use Paytabs\Laravel\Services\PaytabsResultProcessor;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Enums\TranClass;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Exceptions\InvalidConfigurationException;
use Paytabs\Sdk\Profile\Endpoints\Jordan;
use Paytabs\Sdk\Profile\ProfilesFactory;
use Paytabs\Sdk\Request\AbstractRequest;
use Paytabs\Sdk\Request\Payload\PayloadsFactory;
use Paytabs\Sdk\Request\RequestsFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaytabsTest extends TestCase
{
    public function test_the_instance_is_built_from_configuration(): void
    {
        $profile = $this->paytabs()->getProfile();

        $this->assertSame(self::PROFILE_ID, $profile->getProfileId());
        $this->assertSame('https://secure.paytabs.com', $profile->getUrl());
    }

    public function test_the_instance_is_memoised(): void
    {
        $paytabs = $this->paytabs();

        $this->assertSame($paytabs->getInstance(), $paytabs->getInstance());
    }

    #[DataProvider('missingConfiguration')]
    public function test_missing_configuration_is_rejected(string $key, mixed $value): void
    {
        $this->app['config']->set("paytabs.{$key}", $value);

        $this->expectException(InvalidConfigurationException::class);

        $this->paytabs()->getInstance();
    }

    public function test_a_non_numeric_profile_id_is_rejected(): void
    {
        // Without the is_numeric check this casts to 0 and fails much later.
        $this->app['config']->set('paytabs.profile_id', 'not-a-number');

        $this->expectException(InvalidConfigurationException::class);

        $this->paytabs()->getInstance();
    }

    public function test_using_credentials_switches_the_active_profile(): void
    {
        $paytabs = $this->paytabs();

        $paytabs->usingCredentials(999888, 'OTHER-SERVER-KEY', Jordan::CODE);

        $this->assertSame(999888, $paytabs->getProfile()->getProfileId());
        $this->assertSame('https://secure-jordan.paytabs.com', $paytabs->getProfile()->getUrl());
    }

    public function test_using_profile_switches_the_active_profile(): void
    {
        $paytabs = $this->paytabs();

        $paytabs->usingProfile(ProfilesFactory::createProfile('EGY', 555444, 'EGY-SERVER-KEY'));

        $this->assertSame(555444, $paytabs->getProfile()->getProfileId());
    }

    public function test_using_defaults_restores_the_configured_profile(): void
    {
        $paytabs = $this->paytabs();

        $paytabs->usingCredentials(999888, 'OTHER-SERVER-KEY', Jordan::CODE);
        $paytabs->usingDefaults();

        $this->assertSame(self::PROFILE_ID, $paytabs->getProfile()->getProfileId());
    }

    public function test_an_unknown_endpoint_code_is_rejected(): void
    {
        $this->expectException(\Throwable::class);

        $this->paytabs()->usingCredentials(123, 'KEY', 'NOPE');
    }

    public function test_plugin_info_is_attached_to_outgoing_requests(): void
    {
        $request = $this->paymentRequest();

        $this->invokePrepare($request);

        $body = $request->getPayloadObject()->getPayload()->getBody();

        $this->assertArrayHasKey('plugin_info', $body);
        $this->assertSame('Laravel SDK', $body['plugin_info']['cart_name']);
        $this->assertSame(Paytabs::VERSION, $body['plugin_info']['plugin_version']);
    }

    public function test_plugin_info_can_be_disabled(): void
    {
        $this->app['config']->set('paytabs.auto_fill_plugin_info', false);

        $request = $this->paymentRequest();
        $before = $request->getPayloadObject()->getPayload()->getBody()['plugin_info'] ?? null;

        $this->invokePrepare($request);

        $after = $request->getPayloadObject()->getPayload()->getBody()['plugin_info'] ?? null;

        $this->assertSame($before, $after);
    }

    public function test_the_result_processor_is_resolved_with_the_given_profile(): void
    {
        $profile = ProfilesFactory::createProfile('ARE', 111222, 'PINNED-KEY');

        $processor = $this->paytabs()->getResultProcessor($profile);

        $this->assertInstanceOf(PaytabsResultProcessor::class, $processor);
        $this->assertSame($profile, $processor->profile);
    }

    public function test_the_result_processor_defaults_to_no_pinned_profile(): void
    {
        $this->assertNull($this->paytabs()->getResultProcessor()->profile);
    }

    public function test_the_container_binding_is_scoped(): void
    {
        // Scoped keeps mutable SDK state from leaking across Octane requests and queued jobs.
        $this->assertSame($this->app->make(Paytabs::class), $this->app->make(Paytabs::class));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function missingConfiguration(): array
    {
        return [
            'endpoint' => ['endpoint', ''],
            'profile id' => ['profile_id', null],
            'server key' => ['server_key', ''],
        ];
    }

    private function paytabs(): Paytabs
    {
        return $this->app->make(Paytabs::class);
    }

    private function paymentRequest(): AbstractRequest
    {
        $payload = PayloadsFactory::createHostedPage();
        $payload
            ->buildTransaction(TranType::Sale, TranClass::Ecom)
            ->buildCart('order-001', 'AED', 10.5, 'Test order');

        return RequestsFactory::createPaymentRequest($payload);
    }

    private function invokePrepare(AbstractRequest $request): void
    {
        $method = new \ReflectionMethod($this->paytabs(), 'prepareRequest');
        $method->invoke($this->paytabs(), $request);
    }
}
