<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Paytabs\Laravel\PaytabsServiceProvider;
use Paytabs\Laravel\Services\PaytabsResultProcessor;

abstract class TestCase extends Orchestra
{
    protected const SERVER_KEY = 'SJJ9L2MMWT-JHKMTTDTNZ-2R2LBLLHNJ';

    protected const PROFILE_ID = 123456;

    /** Wire format PayTabs uses for payment_result.transaction_time, always UTC. */
    protected const TRANSACTION_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    protected function getPackageProviders($app): array
    {
        return [PaytabsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('paytabs.profile_id', self::PROFILE_ID);
        $app['config']->set('paytabs.server_key', self::SERVER_KEY);
        $app['config']->set('paytabs.endpoint', 'ARE');
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Build an IPN payload array with sane defaults.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function ipnPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'tran_ref' => 'TST2000000000001',
            'tran_type' => 'Sale',
            'cart_id' => 'order-001',
            'cart_description' => 'Test order',
            'cart_currency' => 'AED',
            'cart_amount' => 10.5,
            'tran_total' => 10.5,
            'merchant_id' => 99999,
            'profile_id' => self::PROFILE_ID,
            'ipn_trace' => 'IPN-0000001',
            'trace' => 'PMNT-0001',
            'payment_result' => [
                'response_status' => 'A',
                'response_code' => 'G12345',
                'response_message' => 'Authorised',
                'transaction_time' => gmdate(self::TRANSACTION_TIME_FORMAT),
                'acquirer_ref' => 'acq-1',
                'cvv_result' => ' ',
                'avs_result' => ' ',
            ],
        ], $overrides);
    }

    /**
     * Build a request carrying a correctly signed IPN body.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function signedIpnRequest(array $overrides = [], ?string $signature = null): Request
    {
        $body = json_encode($this->ipnPayload($overrides), JSON_THROW_ON_ERROR);

        return $this->ipnRequest(
            $body,
            $signature ?? hash_hmac('sha256', $body, self::SERVER_KEY),
        );
    }

    protected function ipnRequest(string $body, string $signature): Request
    {
        $request = Request::create('/paytabs/ipn', 'POST', [], [], [], [], $body);
        $request->headers->set('signature', $signature);
        $request->headers->set('content-type', 'application/json');

        return $request;
    }

    protected function processorFor(Request $request): PaytabsResultProcessor
    {
        return $this->app->make(PaytabsResultProcessor::class, ['request' => $request]);
    }

    /**
     * POST a correctly signed IPN through the real HTTP stack and route.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function postSignedIpn(array $overrides = [], ?string $signature = null, string $uri = '/paytabs/ipn'): TestResponse
    {
        $body = json_encode($this->ipnPayload($overrides), JSON_THROW_ON_ERROR);

        return $this->postIpnBody($body, $signature ?? hash_hmac('sha256', $body, self::SERVER_KEY), $uri);
    }

    protected function postIpnBody(string $body, string $signature, string $uri = '/paytabs/ipn'): TestResponse
    {
        return $this->call(
            'POST',
            $uri,
            server: [
                'HTTP_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    /**
     * Build the POST fields PayTabs sends to a return URL.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function browserFields(array $overrides = []): array
    {
        return array_replace([
            'acquirerMessage' => '',
            'acquirerRRN' => '',
            'cartId' => 'order-001',
            'customerEmail' => 'customer@example.test',
            'respCode' => 'G12345',
            'respMessage' => 'Authorised',
            'respStatus' => 'A',
            'tranRef' => 'TST2000000000001',
            'token' => '',
        ], $overrides);
    }

    /**
     * Sign browser fields the way PayTabs does: drop empties and local params, sort, url-encode.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $localParams
     */
    protected function browserSignature(array $fields, array $localParams = []): string
    {
        unset($fields['signature']);

        foreach ($localParams as $localParam) {
            unset($fields[$localParam]);
        }

        $fields = array_filter($fields, static fn ($v): bool => $v !== null && $v !== '');
        ksort($fields);

        return hash_hmac('sha256', http_build_query($fields), self::SERVER_KEY);
    }

    /**
     * Build a signed browser redirect request.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>  $localParams
     */
    protected function signedBrowserRequest(array $overrides = [], array $localParams = [], ?string $signature = null): Request
    {
        $fields = $this->browserFields($overrides);
        $fields['signature'] = $signature ?? $this->browserSignature($fields, $localParams);

        return Request::create('/paytabs/return', 'POST', $fields);
    }
}
