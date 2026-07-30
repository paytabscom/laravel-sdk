<?php

declare(strict_types=1);

namespace Paytabs\Laravel;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Paytabs\Laravel\Services\PaytabsResultProcessor;
use Paytabs\Sdk\Exceptions\InvalidConfigurationException;
use Paytabs\Sdk\Paytabs as PaytabsSdk;
use Paytabs\Sdk\Profile\AbstractEndpoint;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Profile\ProfilesFactory;
use Paytabs\Sdk\Request\AbstractRequest;
use Paytabs\Sdk\Request\Payload\Parts\PluginInfo;
use Paytabs\Sdk\Response\ResponseDirectInterface;

class Paytabs
{
    public const VERSION = '2.0.1';

    private static ?PaytabsSdk $instance = null;

    /**
     * Get the PayTabs SDK instance using default configuration.
     *
     * @return PaytabsSdk The SDK instance
     *
     * @throws InvalidConfigurationException If required configuration is missing
     */
    public static function getInstance(): PaytabsSdk
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::validateConfig();

        $endpoint = Config::get('paytabs.endpoint');
        $profileId = (int) Config::get('paytabs.profile_id');
        $serverKey = Config::get('paytabs.server_key');

        return self::usingCredentials($profileId, $serverKey, $endpoint);
    }

    /**
     * Reset to use default configuration and return instance.
     *
     * @return PaytabsSdk The SDK instance with default config
     */
    public static function usingDefaults(): PaytabsSdk
    {
        self::$instance = null;

        return self::getInstance();
    }

    /**
     * Create SDK instance with specific credentials.
     *
     * @param  int  $profileId  The PayTabs profile ID
     * @param  string  $serverKey  The PayTabs server key
     * @param  AbstractEndpoint|string  $endpoint  The endpoint region or code
     * @return PaytabsSdk The SDK instance
     */
    public static function usingCredentials(
        int $profileId,
        string $serverKey,
        AbstractEndpoint|string $endpoint,
    ): PaytabsSdk {
        $profile = ProfilesFactory::createProfile(
            $endpoint,
            $profileId,
            $serverKey,
        );

        return self::usingProfile($profile);
    }

    /**
     * Create SDK instance with a specific profile.
     *
     * @param  Profile  $profile  The PayTabs profile
     * @return PaytabsSdk The SDK instance
     */
    public static function usingProfile(Profile $profile): PaytabsSdk
    {
        self::$instance = PaytabsSdk::getInstance($profile);

        return self::$instance;
    }

    /**
     * Get the current profile from the SDK instance.
     *
     * @return Profile The current profile
     */
    public static function getProfile(): Profile
    {
        return self::getInstance()->getProfile();
    }

    /**
     * Get the result processor for handling PayTabs callbacks.
     *
     * @param  Profile|null  $profile  Optional profile for validation
     * @return PaytabsResultProcessor The result processor instance
     */
    public static function getResultProcessor(?Profile $profile = null): PaytabsResultProcessor
    {
        return App::make(
            PaytabsResultProcessor::class,
            ['profile' => $profile]
        );
    }

    /**
     * Submit a payment request to PayTabs.
     *
     * @param  AbstractRequest  $request  The payment request
     * @return ResponseDirectInterface The response from PayTabs
     */
    public static function submitRequest(AbstractRequest $request): ResponseDirectInterface
    {
        self::setRequest($request);

        return self::submit();
    }

    /**
     * Set the request on the SDK instance with plugin info.
     *
     * @param  AbstractRequest  $request  The payment request
     * @return PaytabsSdk The SDK instance
     */
    private static function setRequest(AbstractRequest $request): PaytabsSdk
    {
        self::prepareRequest($request);

        return self::getInstance()->setRequest($request);
    }

    /**
     * Submit the request to PayTabs.
     *
     * @return ResponseDirectInterface The response from PayTabs
     */
    private static function submit(): ResponseDirectInterface
    {
        return self::getInstance()->submit();
    }

    /*
    |--------------------------------------------------------------------------
    | Request preparation
    |--------------------------------------------------------------------------
    */

    /**
     * Prepare the request by adding plugin information if enabled.
     *
     * @param  AbstractRequest  $request  The payment request to prepare
     */
    private static function prepareRequest(AbstractRequest $request): void
    {
        $builder = $request->getPayloadObject();
        $payload = $builder->getPayload();

        if ((bool) Config::get('paytabs.auto_fill_plugin_info', true)) {
            $payload->buildBody(new PluginInfo(
                'Laravel SDK',
                App::version(),
                self::VERSION,
            ));
        }
    }

    /**
     * Validate that required configuration values are set.
     *
     * @throws \RuntimeException If required configuration is missing
     */
    private static function validateConfig(): void
    {
        $endpoint = Config::get('paytabs.endpoint');
        $profileId = Config::get('paytabs.profile_id');
        $serverKey = Config::get('paytabs.server_key');

        if (empty($endpoint)) {
            throw InvalidConfigurationException::missing('PAYTABS_ENDPOINT');
        }

        if (empty($profileId)) {
            throw InvalidConfigurationException::missing('PAYTABS_PROFILE_ID');
        }

        if (empty($serverKey)) {
            throw InvalidConfigurationException::missing('PAYTABS_SERVER_KEY');
        }
    }
}
