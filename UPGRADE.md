# Upgrade Guide

## 2.x to 3.0

### 1. Raise your PHP version

The package now requires PHP >= 8.1 (>= 8.3 when running Laravel 13).

### 2. Update calls to `handleIpn()` and `handleCallback()`

Both methods now report the outcome through an `IpnOutcome` passed by reference as
the first argument, and always signal failure with `IpnProcessingException`.

Before:

```php
use Paytabs\Sdk\Exceptions\InvalidSignatureException;

try {
    $ipn = Paytabs::getResultProcessor()->handleIpn(true);
} catch (InvalidSignatureException $e) {
    return response()->json(['status' => 'error'], 401);
}
```

After:

```php
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Exceptions\IpnProcessingException;

$outcome = IpnOutcome::HandlerFailed;

try {
    $ipn = Paytabs::getResultProcessor()->handleIpn($outcome, true);
} catch (IpnProcessingException $e) {
    Log::warning('PayTabs callback not processed', [
        'outcome' => $outcome->name,
        'message' => $e->getMessage(),
    ]);

    return $outcome->toResponse();
}
```

`$outcome` distinguishes `InvalidSignature`, `Stale`, `Duplicate` and `HandlerFailed`.
The underlying `InvalidSignatureException` is still available via `$e->getPrevious()`.

`handleRedirect()` is unchanged and still throws `InvalidSignatureException` directly.

### 3. Implement `release()` on custom idempotency guards

`IpnIdempotencyGuardInterface` now requires a `release()` method, called when your
handler throws so PayTabs can retry the delivery.

```php
public function release(Ipn $payload): void
{
    DB::table('ipn_locks')->where('key', $this->buildKey($payload))->delete();
}
```

### 4. Review exception hierarchy changes

`InvalidPayloadException` and `IdempotencyException` now extend `IpnProcessingException`
rather than `RuntimeException`. Catching `IpnProcessingException` covers all three.

`IdempotencyException::duplicateDelivery()` was removed; use `IdempotencyException::forIpn($ipn, $reason)`.

### 5. Expect idempotency locks to reset once

The idempotency cache key format changed and is now hashed. Locks held by a previous
version are not recognised after deploying. Deploy during a quiet period, or allow
one idempotency TTL (default 180 seconds) to elapse before switching traffic over.

### 6. Move the IPN route out of `routes/web.php`

If you registered the route manually, use `routes/api.php`. The `web` middleware group
applies CSRF verification and rejects PayTabs notifications with a `419` response.
