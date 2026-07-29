# Installation Guide

This guide provides detailed instructions for installing and configuring the PayTabs Laravel SDK in your Laravel application.

## Requirements

Before installing the package, ensure your system meets the following requirements:

- **PHP**: >= 8.1
- **Laravel**: >= 11.0
- **Composer**
- **Extensions**: 
  - `curl` (required by PayTabs PHP SDK)
  - `json` (required by PayTabs PHP SDK)
  - `mbstring` (required by Laravel)

## Installation

### Step 1: Install via Composer

Run the following command in your project root:

```bash
composer require paytabs/laravel-sdk
```

This will install the package and its dependencies, including the PayTabs PHP SDK v3.

### Step 2: Publish Configuration File

Publish the package configuration file to your application:

```bash
php artisan vendor:publish --tag=paytabs-config
```

This will create a `config/paytabs.php` file in your application with default configuration values.

### Step 3: Configure Environment Variables

Add the following required environment variables to your `.env` file:

```env
# PayTabs Configuration
PAYTABS_ENDPOINT=ARE
PAYTABS_PROFILE_ID=your_profile_id_here
PAYTABS_SERVER_KEY=your_server_key_here
```

#### Environment Variables Reference

| Variable | Required | Description | Example |
|----------|----------|-------------|---------|
| `PAYTABS_ENDPOINT` | Yes | PayTabs endpoint region code (ISO 3166-1 alpha-3) | `ARE`, `SAU`, `EGY`, `JOR`, `KWT`, `OMN` |
| `PAYTABS_PROFILE_ID` | Yes | Your PayTabs merchant profile ID | `12345` |
| `PAYTABS_SERVER_KEY` | Yes | Your PayTabs server key from merchant dashboard | `S9K3...` |

### Step 4: Verify Installation

You can verify the installation by checking that the service provider is registered:

```bash
php artisan config:clear
```

Then test in your application:

```php
use Paytabs\Laravel\Facades\Paytabs;

try {
    $profile = Paytabs::getProfile();
    echo "PayTabs SDK installed successfully!";
} catch (\Exception $e) {
    echo "Installation failed: " . $e->getMessage();
}
```

## Configuration

### Basic Configuration

The published `config/paytabs.php` file contains all configurable options:

```php
return [
    // PayTabs endpoint region
    'endpoint' => env('PAYTABS_ENDPOINT', 'ARE'),

    // Your PayTabs credentials
    'profile_id' => env('PAYTABS_PROFILE_ID'),
    'server_key' => env('PAYTABS_SERVER_KEY'),

    // Automatically add plugin info to requests
    'auto_fill_plugin_info' => true,

    // Load package routes automatically
    'load_routes' => true,

    // IPN Configuration
    'ipn_enabled' => true,
    'ipn_route_path' => 'paytabs/ipn',
    'ipn_route_middleware' => ['api'],
    'ipn_handler' => null,
    'ipn_profile_resolver' => null,

    // IPN Idempotency
    'ipn_idempotency_enabled' => true,
    'ipn_idempotency_cache_store' => null,
    'ipn_idempotency_key_prefix' => 'paytabs:ipn',
    'ipn_idempotency_ttl_seconds' => 180,

    // Error handling
    'ack_on_handler_exception' => true,
];
```

### Endpoint Regions

PayTabs supports multiple regional endpoints. Use the appropriate ISO 3166-1 alpha-3 code:

| Region | Code | Endpoint |
|--------|------|----------|
| United Arab Emirates | `ARE` | `https://secure.paytabs.com` |
| Saudi Arabia | `SAU` | `https://secure.paytabs.sa` |
| Egypt | `EGY` | `https://secure-egypt.paytabs.com` |
| Jordan | `JOR` | `https://secure-jordan.paytabs.com` |
| Kuwait | `KWT` | `https://secure-kuwait.paytabs.com` |
| Oman | `OMN` | `https://secure-oman.paytabs.com` |

### IPN Configuration

The package automatically registers an IPN route for handling payment notifications from PayTabs.

#### Custom IPN Route Path

To customize the IPN route path:

```php
'ipn_route_path' => 'webhooks/paytabs',
```

This will change the route from `/paytabs/ipn` to `/webhooks/paytabs`.

#### IPN Middleware

Add custom middleware to the IPN route:

```php
'ipn_route_middleware' => ['api', 'throttle:60,1'],
```

This adds rate limiting (60 requests per minute) to the IPN endpoint.

#### Disable Automatic Route Loading

If you prefer to define the IPN route manually:

```php
'load_routes' => false,
```

Then add the route to your `routes/web.php` or `routes/api.php`:

```php
use Paytabs\Laravel\Http\Controllers\PaytabsResultController;

Route::post('webhooks/paytabs', [PaytabsResultController::class, 'ipn'])
    ->middleware(['api'])
    ->name('paytabs.ipn');
```

### Idempotency Configuration

IPN idempotency prevents duplicate processing of the same notification.

#### Disable Idempotency

```php
'ipn_idempotency_enabled' => false,
```

#### Custom Cache Store

Use a specific cache store for idempotency locks:

```php
'ipn_idempotency_cache_store' => 'redis',
```

#### Custom TTL

Adjust the idempotency lock duration:

```php
'ipn_idempotency_ttl_seconds' => 300, // 5 minutes
```

## Troubleshooting

### Configuration Validation Errors

If you encounter configuration validation errors:

```
PayTabs endpoint is not configured. Please set PAYTABS_ENDPOINT in your environment variables.
```

**Solution**: Ensure all required environment variables are set in your `.env` file and run:

```bash
php artisan config:clear
php artisan cache:clear
```

### cURL Extension Missing

If you see an error about the cURL extension:

```
cURL extension is required
```

**Solution**: Install the cURL extension for your PHP version:


### IPN Route Not Working

If the IPN route is not accessible:

1. Check that routes are loaded:
   ```bash
   php artisan route:list | grep paytabs
   ```

2. Verify `load_routes` is set to `true` in configuration

3. Check middleware configuration doesn't block the route

4. Ensure the route is accessible from PayTabs servers (public URL)

## Testing Installation

Create a test route to verify the installation:

```php
// routes/web.php
use Paytabs\Laravel\Facades\Paytabs;

Route::get('/test-paytabs', function () {
    try {
        $profile = Paytabs::getProfile();
        return response()->json([
            'status' => 'success',
            'endpoint' => $profile->getUrl(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 500);
    }
});
```

Visit `/test-paytabs` in your browser to verify the SDK is working correctly.

## Next Steps

After successful installation:

1. Read the [Usage Guide](USAGE.md) for implementation examples
2. Configure your IPN handler (see [IPN Handling](IPN_HANDLING.md))
3. Test with PayTabs sandbox environment before going live
4. Configure webhook URL (IPN) in your PayTabs merchant dashboard

## Security Best Practices

- **Never commit credentials**: Never commit `.env` file or hardcode credentials
- **Use environment-specific configs**: Use different credentials for development and production
- **Restrict IPN access**: Use middleware to restrict IPN endpoint access
- **Enable HTTPS**: Always use HTTPS in production
- **Monitor logs**: Regularly check logs for failed IPN deliveries or signature validation errors
