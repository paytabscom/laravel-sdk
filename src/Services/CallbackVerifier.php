<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Exceptions\InvalidConfigurationException;
use Paytabs\Laravel\Exceptions\InvalidPayloadException;
use Paytabs\Laravel\Paytabs;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;
use Paytabs\Sdk\Response\Responses\Webhook\TransactionResult\BrowserAsPost;
use Paytabs\Sdk\Response\Responses\Webhook\TransactionResult\Callback;

/**
 * Maps the incoming request to a PayTabs payload and proves it is genuine.
 */
class CallbackVerifier
{
    private ?AbstractTransactionResult $resultIpn = null;

    private ?AbstractTransactionResult $resultBrowser = null;

    /**
     * Create a new callback verifier.
     *
     * @param  Container  $container  The Laravel container
     * @param  Profile|null  $profile  Optional profile pinning verification to one merchant
     * @param  Request|null  $request  Optional request, resolved from the container when omitted
     */
    public function __construct(
        private readonly Container $container,
        private readonly ?Profile $profile = null,
        private readonly ?Request $request = null,
    ) {}

    /**
     * The memoised IPN transaction result, shared with the merchant handler.
     *
     * @return AbstractTransactionResult The transaction result for the current request
     */
    public function ipnResult(): AbstractTransactionResult
    {
        if ($this->resultIpn === null) {
            $request = $this->request();

            // Read from the framework request, not php://input, so this works under Octane.
            $this->resultIpn = Callback::initWith(
                $request->getContent(),
                $this->flattenHeaders($request),
            );
        }

        return $this->resultIpn;
    }

    /**
     * Verify the current request as an IPN callback.
     *
     * @return Ipn The verified payload
     *
     * @throws InvalidSignatureException If signature validation fails
     * @throws InvalidPayloadException If the payload cannot be mapped
     * @throws InvalidConfigurationException If the configured profile resolver is invalid
     */
    public function verifyIpn(): Ipn
    {
        $payload = $this->verify($this->ipnResult());

        if ($payload instanceof Browser) {
            throw new InvalidPayloadException('Expected Ipn payload, got Browser payload.');
        }

        return $payload;
    }

    /**
     * Verify the current request as a browser redirect callback.
     *
     * @return Browser The verified browser payload
     *
     * @throws InvalidSignatureException If signature validation fails
     * @throws InvalidPayloadException If the payload is an IPN rather than a browser callback
     * @throws InvalidConfigurationException If the configured profile resolver is invalid
     */
    public function verifyBrowser(): Browser
    {
        $payload = $this->verify($this->browserResult());

        if ($payload instanceof Ipn) {
            throw new InvalidPayloadException('Expected Browser payload, got Ipn payload.');
        }

        return $payload;
    }

    private function request(): Request
    {
        return $this->request ?? $this->container->make(Request::class);
    }

    private function browserResult(): AbstractTransactionResult
    {
        if ($this->resultBrowser === null) {
            // The SDK strips these before hashing, so params the merchant added to their
            // own return URL do not break signature verification.
            $localParams = array_values(array_filter(
                array_map('strval', (array) Config::get('paytabs.browser_local_params', [])),
            ));

            $this->resultBrowser = BrowserAsPost::initWith(
                $this->browserFields(),
                $localParams,
            );
        }

        return $this->resultBrowser;
    }

    /**
     * Reduce the POST bag to the flat string map the SDK hashes.
     *
     * @return array<string, string>
     */
    private function browserFields(): array
    {
        $fields = [];

        foreach ($this->request()->request->all() as $name => $value) {
            // Casting an array would yield the literal "Array" and corrupt the hash.
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $fields[$name] = (string) ($value ?? '');
        }

        return $fields;
    }

    /**
     * Reduce Symfony's multi-value header bag to the single-value shape the SDK expects.
     *
     * @return array<string, string>
     */
    private function flattenHeaders(Request $request): array
    {
        $headers = [];

        // Symfony's request headers bag is multi-value, but the SDK expects single-value headers.
        // We take the first value for each header, defaulting to an empty string if no values are present.
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    /**
     * Map and signature-check a transaction result.
     *
     * @param  AbstractTransactionResult  $transactionResult  The transaction result to verify
     * @return Browser|Ipn The verified payload
     */
    private function verify(AbstractTransactionResult $transactionResult): Browser|Ipn
    {
        $payload = $transactionResult->getPayload();

        if ($payload === null) {
            throw new InvalidPayloadException('Failed to map payload from transaction result.');
        }

        /** @var Ipn|Browser $mappedPayload */
        $mappedPayload = $payload->getMapped();

        $resolvedProfile = $this->resolveProfile($mappedPayload);

        $transactionResult->setProfile($resolvedProfile);

        if (! $transactionResult->isGenuine()) {
            Log::warning('PayTabs callback rejected: invalid signature.', [
                'tran_ref' => $mappedPayload->tran_ref ?? null,
                // Non-reversible, so the public endpoint cannot be used to harvest key material from logs.
                'server_key_fingerprint' => substr(hash('sha256', $resolvedProfile->getServerKeyPrefix()), 0, 8),
            ]);

            throw new InvalidSignatureException;
        }

        return $mappedPayload;
    }

    private function resolveProfile(Browser|Ipn $mappedPayload): Profile
    {
        return $this->profile
            ?? PaytabsResolver::resolveProfile($this->container, $mappedPayload)
            ?? $this->container->make(Paytabs::class)->getProfile();
    }
}
