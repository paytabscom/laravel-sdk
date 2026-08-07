<?php

declare(strict_types=1);

use Paytabs\Sdk\Profile\Endpoints\Uae;

return [
    /*
    |--------------------------------------------------------------------------
    | PayTabs Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials and configuration settings
    | for the PayTabs payment gateway integration.
    |
    */

    /** The country's ISO 3166-1 alpha-3 code */
    'endpoint' => env('PAYTABS_ENDPOINT', Uae::CODE), // or "ARE", "SAU", "EGY", "JOR", "KWT", "OMN" ...

    'profile_id' => env('PAYTABS_PROFILE_ID'),

    'server_key' => env('PAYTABS_SERVER_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Auto Fill Plugin Info
    |--------------------------------------------------------------------------
    |
    | This option determines whether the plugin information should be
    | automatically included in the request payload.
    |
    */
    'auto_fill_plugin_info' => true,

    /*
    |--------------------------------------------------------------------------
    | Package Routes
    |--------------------------------------------------------------------------
    |
    */
    'load_routes' => true,

    'ipn_enabled' => true,

    /** Customizable IPN callback route path. */
    'ipn_route_path' => 'paytabs/ipn',

    /** Middleware stack applied to the package IPN route. */
    'ipn_route_middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | IPN Handler
    |--------------------------------------------------------------------------
    |
    | Optional handler class for IPN processing.
    | handleIpn() is called only for verified payloads. The class must
    | implement:
    | Paytabs\Laravel\Contracts\IpnHandlerInterface
    |
    */
    'ipn_handler' => null,

    /*
    |--------------------------------------------------------------------------
    | PayTabs Result Profile Resolver
    |--------------------------------------------------------------------------
    |
    | Optional class to select a profile for validating
    | PayTabs result callbacks (including IPN). resolveProfile() is called with
    | the mapped payload. The class must implement:
    | Paytabs\Laravel\Contracts\ProfileResolverInterface
    |
    */
    'ipn_profile_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | IPN Idempotency
    |--------------------------------------------------------------------------
    |
    | Idempotency avoids duplicate processing when gateway retries the same
    | callback. The default implementation uses Laravel cache add() as a lock.
    |
    */
    'ipn_idempotency_enabled' => true,

    /** Optional cache store name, null uses default store. */
    'ipn_idempotency_cache_store' => null,

    /** Key prefix used for idempotency lock keys. */
    'ipn_idempotency_key_prefix' => 'paytabs:ipn',

    /** Lock TTL in seconds. */
    'ipn_idempotency_ttl_seconds' => 180,

    /*
    |--------------------------------------------------------------------------
    | Callback Failure Policy
    |--------------------------------------------------------------------------
    |
    | When true, handler exceptions are logged and the callback endpoint still
    | acknowledges receipt. Disable this if you want callback requests to fail
    | with an error status to trigger upstream retries.
    |
    */
    'ack_on_handler_exception' => false,

    /*
    |--------------------------------------------------------------------------
    | IPN Time Guard
    |--------------------------------------------------------------------------
    |
    | When enabled, IPNs with a transaction_time older than the configured TTL
    | are ignored. This is to avoid processing stale IPNs that may be retried
    | by the gateway after a long delay.
    | Also prevents Replay Attacks.
    |
    */
    'ipn_time_guard_enabled' => true,

    /** Time guard TTL in seconds. IPNs older than this are ignored. */
    'ipn_time_guard_ttl_seconds' => 3600,

    /** Tolerance in seconds for IPNs timestamped ahead of server time. */
    'ipn_time_guard_future_skew_seconds' => 300,
];
