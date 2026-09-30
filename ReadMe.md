# PayTabs Laravel SDK

Official PayTabs Laravel SDK for Payment Gateway integrations. This package provides a Laravel-friendly wrapper around the PayTabs PHP SDK, making it easy to integrate PayTabs payment services into your Laravel application.

[PayTabs PHP SDK](https://github.com/paytabscom/php-sdk)

## Features

- **Seamless Laravel Integration**: Built as a proper Laravel package with Service Provider and Facade support
- **Configuration Management**: Environment-based configuration with sensible defaults
- **IPN Handling**: Built-in Instant Payment Notification (IPN) handling with idempotency support
- **Profile Resolution**: Support for multiple PayTabs profiles with custom resolvers
- **Transactions API**: High-level service for common operations (Query, Capture, Refund, Void) with clean response object wrappers
- **Signature Validation**: Automatic signature verification for callbacks
- **Type Safety**: Full PHP 8.1+ type hints and comprehensive PHPDoc blocks
- **Idempotency**: Cache-based duplicate delivery prevention for IPNs

## Requirements

- PHP >= 8.1 (>= 8.3 when using Laravel 13)
- Laravel >= 10.0
- PayTabs PHP SDK v3

## Installation

Install the package via Composer:

```bash
composer require paytabs/laravel-sdk
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=paytabs-config
```

## Quick Start

### 1. Configure Environment Variables

Add the following to your `.env` file:

```env
PAYTABS_ENDPOINT=ARE
PAYTABS_PROFILE_ID=your_profile_id
PAYTABS_SERVER_KEY=your_server_key
```

### 2. Create a Payment Request

```php
use Paytabs\Laravel\Facades\Paytabs;

$response = Paytabs::payment()->createHostedPage(
    cart_id: 'order-001',
    amount: 99.5,
    currency: 'AED',
    description: 'Order Payment',
    customer: [
        'name' => 'Fname Lname',
        'email' => 'customer@example.com',
        'phone' => '+971500000000',
        'street' => '12345',
        'city' => 'City',
        'state' => 'State',
        'country' => 'UAE',
    ]
);

if ($response->isFailure()) {
    echo $response->getCode() . ' - ' . $response->getMessage();
    exit;
}

if ($response->isRedirect()) {
    $paymentUrl = $response->getRedirectUrl();
    // return redirect($paymentUrl);
}
```

### 3. Handle IPN Callbacks

The package automatically registers an IPN route at `/paytabs/ipn`. Configure your IPN handler in `config/paytabs.php`:

```php
'ipn_handler' => \App\Services\PaytabsIpnHandler::class,
```

Create your handler:

```php
<?php

namespace App\Services;

use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class PaytabsIpnHandler implements IpnHandlerInterface
{
    public function handleIpn(
        AbstractTransactionResult $transactionResult,
        Ipn $mappedPayload
    ): void {
        // Update your order status
        if ($mappedPayload->isPaymentSuccessful()) {
            // Payment successful

            // Fetch order by Tran Ref
            $order = Order::where('tran_ref', $mappedPayload->tran_ref)->first();
            // Fetch order by Order id
            $order = Order::find($mappedPayload->cart_id);

            $order->update(['status' => 'paid']);
        }
    }
}
```

### 4. Manage Transactions (Query, Capture, Refund, Void)

Use the `Paytabs::transactions()` service to easily query, capture, refund, or void transactions:

```php
use Paytabs\Laravel\Facades\Paytabs;

// 1. Query transaction status
$response = Paytabs::transactions()->query('TST240000000001');

if ($response->isSuccess() && $response->isAuthorized()) {
    $tranRef = $response->getTranRef();
    $message = $response->getMessage();
}

// 2. Capture an authorized transaction
$response = Paytabs::transactions()->capture(
    tran_ref: 'TST240000000001',
    cart_id: 'order-001',
    cart_amount: 99.50,
    cart_currency: 'AED',
    cart_description: 'Capture payment for order-001'
);

// 3. Refund a captured or sale transaction
$response = Paytabs::transactions()->refund(
    tran_ref: 'TST240000000001',
    cart_id: 'refund-001',
    cart_amount: 50.00,
    cart_currency: 'AED',
    cart_description: 'Refund for order-001'
);

// 4. Void an authorized transaction
$response = Paytabs::transactions()->void(
    tran_ref: 'TST240000000001',
    cart_id: 'void-001',
    cart_amount: 99.50,
    cart_currency: 'AED',
    cart_description: 'Void authorization'
);
```

#### Working with `TransactionResponse`

All transaction operations return a clean `TransactionResponse` instance with convenience methods:

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isSuccess()` | `bool` | Check if the API request was processed successfully |
| `isAuthorized()` | `bool` | Check if the transaction was Authorized/Approved (`'A'`) |
| `getTranRef()` | `?string` | Get the transaction reference |
| `getMessage()` | `string` | Get the API response message |
| `getCartId()` | `?string` | Get the cart ID associated with the transaction |
| `getCartAmount()` | `?float` | Get the cart amount |
| `getCartCurrency()` | `?string` | Get the cart currency |
| `getTranType()` | `?string` | Get the transaction type (e.g. Sale, Auth, Capture, Refund, Void) |
| `getPaymentResult()` | `?object` | Get the raw payment result object |
| `raw()` | `ResponseDirectInterface` | Access the underlying core SDK direct response |

## Documentation

- [Installation Guide](INSTALLATION.md) - Detailed installation and configuration instructions
- [Usage Guide](USAGE.md) - Comprehensive usage examples and patterns
- [IPN Handling](IPN_HANDLING.md) - Deep dive into IPN processing and customization

## Configuration

The package configuration file `config/paytabs.php` includes the following options:

| Option | Description | Default |
|--------|-------------|---------|
| `endpoint` | PayTabs endpoint region code | `ARE` |
| `profile_id` | Your PayTabs profile ID | - |
| `server_key` | Your PayTabs server key | - |
| `auto_fill_plugin_info` | Automatically add Laravel SDK info to requests | `true` |
| `load_routes` | Load package routes automatically | `true` |
| `ipn_enabled` | Enable IPN handling | `true` |
| `ipn_route_path` | Custom IPN route path | `paytabs/ipn` |
| `ipn_route_middleware` | Middleware for IPN route | `['api', 'throttle:60,1']` |
| `ipn_handler` | Required IPN handler class | - |
| `ipn_profile_resolver` | Custom profile resolver class | - |
| `ipn_idempotency_enabled` | Enable IPN idempotency checks | `true` |
| `ipn_idempotency_cache_store` | Cache store for idempotency | `null` (default) |
| `ipn_idempotency_key_prefix` | Cache key prefix | `paytabs:ipn` |
| `ipn_idempotency_ttl_seconds` | Idempotency lock TTL | `180` |
| `ack_on_handler_exception` | Acknowledge IPN even if handler fails | `false` |
| `ipn_time_guard_enabled` | Enable transaction time Guard | `true` |
| `ipn_time_guard_ttl_seconds` | Transaction time guard TTL (seconds) | `3600` |
| `ipn_time_guard_future_skew_seconds` | Tolerance for IPNs ahead of server time | `300` |

## Using Multiple Profiles

If you need to use different PayTabs profiles for different transactions:

```php
use Paytabs\Laravel\Facades\Paytabs;
use Paytabs\Sdk\Profile\Endpoints\Jordan;
use Paytabs\Sdk\Profile\ProfilesFactory;
use Paytabs\Sdk\Profile\EndpointsFactory;

// Create a custom profile
$profile = ProfilesFactory::createProfile(
    EndpointsFactory::getKsaEndpoint(),
    12345,
    'your_server_key'
);

// Use the custom profile
Paytabs::usingProfile($profile);

// Or use credentials directly
Paytabs::usingCredentials(12345, 'your_server_key', Jordan::CODE);

// Reset to default configuration
Paytabs::usingDefaults();
```

## Security

- **Signature Validation**: All callbacks are automatically verified using PayTabs signature validation
- **Idempotency**: Duplicate IPN deliveries are prevented using cache-based locks
- **Configuration**: Sensitive credentials should be stored in environment variables
- **Middleware**: IPN endpoint supports custom middleware for additional security layers

## Support

For issues and questions:
- GitHub Issues: [PayTabs Laravel SDK](https://github.com/paytabscom/laravel-sdk)
- PayTabs PHP SDK Documentation (Chat mode): [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/paytabscom/laravel-sdk)
- PayTabs Official Documentation: [PayTabs Docs](https://docs.paytabs.com/)

## License

This package is distributed under a proprietary license. See LICENSE.md for full terms.
