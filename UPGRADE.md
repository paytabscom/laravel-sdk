# Upgrade Guide

## 2.x to 3.0


### 1. Update calls to `handleIpn()` and `handleCallback()`

Both methods now return an `IpnResult` value object instead of the payload, and no
longer throw when a callback is rejected. A rejection is an expected outcome, not an
exceptional condition.

Before:

```php
use Paytabs\Sdk\Exceptions\InvalidSignatureException;

try {
    $ipn = Paytabs::getResultProcessor()->handleIpn(true);
} catch (InvalidSignatureException $e) {
    return response()->json(['status' => 'error'], 403);
}
```

After:

```php
$result = Paytabs::getResultProcessor()->handleIpn(true);

if (! $result->isProcessed()) {
    Log::warning('PayTabs callback not processed', [
        'outcome' => $result->outcome->name,
        'reason' => $result->reason,
    ]);

    return $result->toResponse();
}

$ipn = $result->payload;
```

`IpnResult` exposes:

| Property / method | Type | Description |
|---|---|---|
| `$result->outcome` | `IpnOutcome` | What happened to the delivery |
| `$result->payload` | `?Ipn` | The verified payload, non-null only when `Processed` |
| `$result->cause` | `?Throwable` | The underlying error, when verification failed |
| `$result->reason` | `?string` | Human readable rejection description |
| `$result->isProcessed()` | `bool` | Whether the payload is present and verified |
| `$result->toResponse()` | `JsonResponse` | The response to return to PayTabs |

`$result->outcome` distinguishes `InvalidSignature`, `InvalidPayload`, `Stale`,
`Duplicate` and `HandlerFailed`. The underlying `InvalidSignatureException` is
available via `$result->cause`.

`handleRedirect()` still throws rather than returning an outcome. It can raise
`InvalidSignatureException`, `InvalidPayloadException` (unmappable payload, or an IPN
delivered to the redirect endpoint) and `InvalidConfigurationException` (misconfigured
profile resolver).


### 2. Configure an IPN handler

`paytabs.ipn_handler` is no longer optional. Previously a missing handler logged a warning
and still answered `200`, so PayTabs treated the notification as delivered and never retried
it. That silently lost every payment on a misconfigured deployment.

A delivery with no handler configured now responds `500`, so PayTabs retries while you fix
the configuration.

```php
// config/paytabs.php
'ipn_handler' => \App\Services\PaytabsIpnHandler::class,
```

The class must implement `Paytabs\Laravel\Contracts\IpnHandlerInterface`. `PaytabsResolver::resolveIpnHandler()`
now returns `IpnHandlerInterface` rather than `?IpnHandlerInterface`, and throws
`InvalidConfigurationException` when the handler is missing or does not implement the contract.


### 3. Update assertions on the invalid-signature status code

`IpnOutcome::InvalidSignature` now responds `403` instead of `401`. The endpoint issues no
authentication challenge, so `401` was the wrong status. Update any test or monitoring rule
that asserts on `401`.


### 4. Do not use the `null` cache store for idempotency

`Illuminate\Cache\NullStore` never stores a value, so `acquire()` always reported a duplicate
and every IPN was ignored with a `200`. That configuration now throws
`InvalidConfigurationException` instead of failing silently.

Use a shared, persistent store in production. The `array` driver is per-process and gives no
deduplication across workers.

```php
'ipn_idempotency_cache_store' => 'redis',
```


### 5. Implement `release()` on custom idempotency guards

`IpnIdempotencyGuardInterface` now requires a `release()` method, called when your
handler throws so PayTabs can retry the delivery.

```php
public function release(Ipn $payload): void
{
    DB::table('ipn_locks')->where('key', $this->buildKey($payload))->delete();
}
```

### 6. Review exception hierarchy changes

`handleIpn()` and `handleCallback()` no longer throw for rejected callbacks, so there is
nothing left to catch around them. `IdempotencyException` was removed; a duplicate delivery
is now reported as `IpnOutcome::Duplicate`.

`InvalidPayloadException` still extends `IpnProcessingException` and is thrown by
`handleRedirect()`. When a callback payload is malformed, `handleCallback()` reports
`IpnOutcome::InvalidPayload` and exposes the exception via `$result->cause`.

A malformed payload now responds `422` instead of `500`, so PayTabs stops retrying a
delivery that can never succeed.


### 7. Expect idempotency locks to reset once

The idempotency cache key format changed and is now hashed. Locks held by a previous
version are not recognised after deploying. Deploy during a quiet period, or allow
one idempotency TTL (default 180 seconds) to elapse before switching traffic over.


### 8. Move the IPN route out of `routes/web.php`

The `web` middleware group applies CSRF verification and rejects PayTabs notifications with a `419` response.
If you registered the route manually:
- Use `routes/api.php`.
- OR: skip the CSRF middleware check:
    ```
    // Laravel 11
    $paytabsIpnRoute->withoutMiddleware([ValidateCsrfToken::class])

    // Laravel 13
    $paytabsIpnRoute->withoutMiddleware([PreventRequestForgery::class])
    ```
