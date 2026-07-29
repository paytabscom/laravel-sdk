# IPN Handling Guide

This guide provides comprehensive information about handling Instant Payment Notifications (IPN) from PayTabs in your Laravel application.

## Table of Contents

- [IPN Overview](#ipn-overview)
- [Setting Up IPN Handler](#setting-up-ipn-handler)
- [Custom Handler Implementation](#custom-handler-implementation)
- [Idempotency Configuration](#idempotency-configuration)
- [Profile Resolver Usage](#profile-resolver-usage)
- [Security Considerations](#security-considerations)
- [Troubleshooting](#troubleshooting)

## IPN Overview

### What is IPN?

Instant Payment Notification (IPN) is a webhook service provided by PayTabs that sends real-time payment status updates to your server when payment events occur. This ensures your application receives payment updates for any payment action happened on the merchant's portal.

### How It Works

1. Transaction status changed on PayTabs.
2. PayTabs sends a POST request to your configured IPN URL
3. Your server receives and validates the notification
4. Your IPN handler processes the payment status
5. Your server responds to acknowledge receipt

### IPN vs Redirect Callbacks

| Feature | IPN | Redirect Callback |
|---------|-----|------------------|
| **Reliability** | High - server-to-server | Medium - depends on customer |
| **Timing** | Real-time | After customer action |
| **Guaranteed Delivery** | Yes (with retries) | No |
| **Use Case** | Order status updates | User experience |

## Setting Up IPN Handler

### Step 1: Configure IPN Route

The package automatically registers an IPN route at `/paytabs/ipn`. Verify it's configured in `config/paytabs.php`:

```php
'ipn_enabled' => true,
'ipn_route_path' => 'paytabs/ipn',
```

### Step 2: Configure Webhook URL in PayTabs Dashboard

1. Log in to your PayTabs merchant dashboard
2. Navigate to Developers → Payment Notifications → Configuration
3. Add your IPN URL: `https://yourdomain.com/paytabs/ipn`
4. Select events to receive (Sale, Auth, Refund, etc.)
5. Save configuration

### Step 3: Create IPN Handler

Create a class that implements `IpnHandlerInterface`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Sdk\Enums\TranStatus;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;

class PaytabsIpnHandler implements IpnHandlerInterface
{
    public function handleIpn(
        AbstractTransactionResult $transactionResult,
        Ipn $mappedPayload
    ): void {
        /** @var TranType */
        $tranType = $mappedPayload->tranType;

        /** @var TranStatus $status */
        $status = $mappedPayload->payment_result->tranStatus;

        $tranRef = $mappedPayload->tran_ref;
        $orderId = $mappedPayload->cart_id;

        Log::info('IPN received', [
            'tran_ref' => $tranRef,
            'type' => $tranType->value,
            'status' => $status->value,
            'order_amount' => $mappedPayload->cart_amount,
            'tran_amount' => $mappedPayload->tran_total,
        ]);

        // Find the order
        $order = null;
        // Find Order by Tran Ref or Cart ID

        if (! $order) {
            Log::warning('Order not found for IPN', ['tran_ref' => $tranRef, 'cart_id' => $orderId]);

            return;
        }

        // Handle the transaction type
        switch ($tranType) {
            case TranType::Auth:
            case TranType::Register:
            case TranType::Sale:

            case TranType::AuthExt:
                // Auth Extension is used to refresh the hold on the funds
                // Followup an Auth transaction

            case TranType::PaymentRequest:

            case TranType::Capture:
            case TranType::Void:
            case TranType::Release:
            case TranType::Refund:

            default:
                Log::warning('Unknown transaction type', [
                    'tran_ref' => $tranRef,
                    'type' => $tranType->value,
                ]);
        }

        // Handle the transaction status

        $tranStatus = $status->isSuccessful();
        $tranStatus = $status->isOnHold();
        $tranStatus = $status->isFailed();
        $tranStatus = $status->isNotFinal();
        $tranStatus = $status->isExpired();
        $tranStatus = $status->isPending();
        // ...
    }
}
```

### Step 4: Register Handler

Add your handler to `config/paytabs.php`:

```php
'ipn_handler' => \App\Services\PaytabsIpnHandler::class,
```

## Custom Handler Implementation

### Custom Handler with all essential steps

```php
namespace App\Services;

use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Paytabs;
use Paytabs\Sdk\Enums\TranStatus;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\TransactionResult\Callback;

class PaytabsCustomIpnHandler
{
    public function handleIpn(): void
    {
        $ipnRequest = Callback::init();

        // Set the profile for the IPN validation
        $ipnRequest->setProfile(Paytabs::getProfile());

        // Validate the IPN request signature
        $isGenuine = $ipnRequest->isGenuine();
        if (! $isGenuine) {
            throw InvalidSignatureException::mismatch(Paytabs::getProfile()->getServerKeyPrefix());
        }

        /** @var Ipn|Browser $mappedPayload */
        $mappedPayload = $ipnRequest->getPayload()->getMapped();

        if ($mappedPayload instanceof Browser) {
            Log::error('Expected IPN type, not Browser type', []);

            return;
        }

        /** @var Ipn $mappedPayload */

        // Check for Idempotency: If the transaction has already been processed,
        // you can skip further processing to avoid duplicate actions.

        // PayTabs provides a method to check if the IPN has already been processed.
        Paytabs::getResultProcessor()->shouldProcessIpn($mappedPayload);

        // Continue processing the IPN payload

        /** @var TranType */
        $tranType = $mappedPayload->tranType;

        /** @var TranStatus $status */
        $status = $mappedPayload->payment_result->tranStatus;

        $tranRef = $mappedPayload->tran_ref;
        $orderId = $mappedPayload->cart_id;
    }
}
```

### Handler with PayTabs helpers

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Exceptions\IdempotencyException;
use Paytabs\Laravel\Paytabs;
use Paytabs\Sdk\Enums\TranStatus;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class PaytabsCustomIpnHandler
{
    public function handleIpn()
    {
        try {
            $mappedPayload = Paytabs::getResultProcessor()->handleIpn(true);
        } catch (InvalidSignatureException $e1) {
            Log::alert('Invalid signature in PayTabs callback', ['message' => $e1->getMessage()]);

            return response(['message' => 'Invalid signature'], 401);
        } catch (IdempotencyException $e2) {
            Log::warning('Duplicate PayTabs callback', ['message' => $e2->getMessage()]);

            return response(['message' => 'Duplicate detected'], 200);
        }

        // Continue processing the IPN payload

        /** @var TranType */
        $tranType = $mappedPayload->tranType;

        /** @var TranStatus $status */
        $status = $mappedPayload->payment_result->tranStatus;

        $tranRef = $mappedPayload->tran_ref;
        $orderId = $mappedPayload->cart_id;
    }
}
```

## Idempotency Configuration

### What is Idempotency?

Idempotency ensures that duplicate IPN deliveries (which can happen due to network retries) are processed only once. The package uses Laravel's cache to implement this.

### Default Configuration

```php
'ipn_idempotency_enabled' => true,
'ipn_idempotency_cache_store' => null, // Uses default cache store
'ipn_idempotency_key_prefix' => 'paytabs:ipn',
'ipn_idempotency_ttl_seconds' => 180, // 3 minutes
```

### Custom Cache Store

Use Redis for distributed systems:

```php
'ipn_idempotency_cache_store' => 'redis',
```

### Custom TTL

Adjust based on your retry window:

```php
'ipn_idempotency_ttl_seconds' => 600, // 10 minutes
```

### Disable Idempotency

If you implement your own duplicate detection:

```php
'ipn_idempotency_enabled' => false,
```

### Custom Idempotency Guard

Create a custom idempotency guard by implementing `IpnIdempotencyGuardInterface`:

```php
<?php

namespace App\Services;

use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class DatabaseIdempotencyGuard implements IpnIdempotencyGuardInterface
{
    public function acquire(Ipn $payload): bool
    {
        $key = $this->buildKey($payload);
        
        // Use database lock instead of cache
        $lock = DB::table('ipn_locks')
            ->where('key', $key)
            ->lockForUpdate()
            ->first();
        
        if ($lock) {
            // Already processed
            return false;
        }
        
        // Acquire lock
        DB::table('ipn_locks')->insert([
            'key' => $key,
            'tran_ref' => $payload->tran_ref,
            'created_at' => now(),
        ]);
        
        return true;
    }
    
    private function buildKey(Ipn $payload): string
    {
        return sprintf(
            'ipn:%d:%s:%s',
            $payload->profile_id,
            $payload->ipn_trace,
            $payload->tran_ref
        );
    }
}
```

Register in your service provider:

```php
$this->app->bind(
    IpnIdempotencyGuardInterface::class,
    DatabaseIdempotencyGuard::class
);
```

## Profile Resolver Usage

### When to Use Profile Resolver

Use a profile resolver when:
- You have multiple PayTabs merchant accounts
- You're building a multi-tenant application
- Different transactions use different profiles based on business logic

Note: if no profile resolver, the SDK will get the configuration from the env/config.
Profile resolver is used when:
- The Laravel App receives a callback/IPN
- The Laravel App uses multiple profiles/accounts
It is essential to match the correct profile, to validate the signature.

### Basic Profile Resolver

```php
<?php

namespace App\Services;

use Paytabs\Laravel\Contracts\ProfileResolverInterface;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Profile\ProfilesFactory;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class MerchantProfileResolver implements ProfileResolverInterface
{
    public function resolveProfile(Ipn|Browser $transactionResult): ?Profile
    {
        // Extract merchant ID from cart_id or custom field
        $merchantId = $transactionResult->cart_id;
        
        $merchant = Merchant::find($merchantId);
        
        if (!$merchant) {
            return null; // Use default profile
        }
        
        return ProfilesFactory::createProfile(
            $merchant->endpoint,
            $merchant->paytabs_profile_id,
            $merchant->paytabs_server_key
        );
    }
}
```


### Configure Profile Resolver

Add to `config/paytabs.php`:

```php
'ipn_profile_resolver' => \App\Services\MerchantProfileResolver::class,
```

## Security Considerations

### Signature Validation

The package automatically validates PayTabs signatures for all IPN callbacks. Invalid signatures are rejected with a 401 response.

### IPN Endpoint Security

#### 1. Use HTTPS

Always use HTTPS in production to prevent man-in-the-middle attacks.

#### 2. Add Rate Limiting

Configure rate limiting in `config/paytabs.php`:

```php
'ipn_route_middleware' => ['api', 'throttle:60,1'],
```

#### 3. Restrict by IP

Create middleware to restrict access to PayTabs IP addresses:

```php
<?php


namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaytabsIpWhitelist
{
    private array $allowedIps = [
        '40.123.210.168',
        // Add PayTabs IP addresses
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->ip(), $this->allowedIps)) {
            return response('Access Denied.', 403);
        }

        return $next($request);
    }
}
```

Add to middleware:

```php
'ipn_route_middleware' => ['api', \App\Http\Middleware\PaytabsIpWhitelist::class],
```

#### 4. Verify Timestamp

The SDK automatically checks and prevents old requests.
Just configure the `paytabs.ipn_time_guard_enabled` & `paytabs.ipn_time_guard_ttl_seconds`


### Logging

Log all IPN events for audit and debugging:

```php
public function handleIpn(
    AbstractTransactionResult $transactionResult,
    Ipn $mappedPayload
): void {
    Log::info('IPN received', [
        'tran_ref' => $mappedPayload->tran_ref,
        'status' => $mappedPayload->payment_result->response_status,
        'amount' => $mappedPayload->cart_amount,
        'currency' => $mappedPayload->cart_currency,
        'ip' => request()->ip(),
        'user_agent' => request()->user_agent(),
    ]);
    
    // Process IPN
    // ...
}
```

### Error Handling

Configure error handling behavior:

```php
'ack_on_handler_exception' => true, // Acknowledge even if handler fails
```

When set to `true`, the IPN endpoint returns 200 even if your handler throws an exception. This prevents PayTabs from retrying indefinitely. Set to `false` if you want PayTabs to retry on handler failures.

## Troubleshooting

### IPN Not Received

1. **Check Webhook URL**: Verify the webhook URL is correctly configured in PayTabs dashboard
2. **Check Route**: Verify the IPN route is registered:
   ```bash
   php artisan route:list | grep paytabs
   ```
3. **Check Firewall**: Ensure your server allows incoming POST requests from PayTabs
4. **Check Logs**: Review Laravel logs for errors:
   ```bash
   tail -f storage/logs/laravel.log
   ```

### Signature Validation Failed

1. **Verify Profile**: Ensure the correct profile is being used for validation
2. **Check Server Key**: Verify the server key matches the one in PayTabs dashboard
3. **Profile Resolver**: If using a profile resolver, ensure it returns the correct profile

### Duplicate IPN Processing

1. **Check Idempotency**: Verify idempotency is enabled
2. **Check Cache**: Ensure cache is working properly
3. **Check TTL**: Verify TTL is long enough for your retry window

### Handler Not Called

1. **Check Configuration**: Verify `ipn_handler` is set in config
2. **Check Interface**: Ensure handler implements `IpnHandlerInterface`
3. **Check Logs**: Look for warnings about missing handler

### Testing IPN Locally

Use ngrok or similar tools to test IPN locally:

```bash
ngrok http --host-header=rewrite 443
```

Configure webhook URL in PayTabs dashboard with the ngrok URL.

### Manual IPN Testing

Create a test route to simulate IPN:

[See PHP SDK samples](https://github.com/paytabscom/php-sdk/blob/main/Samples/ResultCallback.php)

## Best Practices

1. **Always Process IPN Asynchronously**: Use queues for heavy processing
2. **Implement Retry Logic**: Handle transient failures gracefully
3. **Monitor IPN Delivery**: Track IPN delivery rates and failures
4. **Keep Handlers Fast**: Minimize processing time in the handler
5. **Use Database Transactions**: Ensure data consistency
6. **Log Everything**: Maintain comprehensive logs for debugging
7. **Test Thoroughly**: Test with sandbox before production
8. **Handle Edge Cases**: Account for all possible payment statuses
9. **Implement Alerts**: Set up alerts for IPN failures
10. **Document Your Flow**: Maintain clear documentation of your IPN flow

## Next Steps

- Read [Usage Guide](USAGE.md) for payment request examples
- Review [Installation Guide](INSTALLATION.md) for configuration details
- Check [PayTabs Documentation](https://docs.paytabs.com/) for webhook specifications
