# Usage Guide

This guide provides comprehensive examples and patterns for using the PayTabs Laravel SDK in your application.

##

Refer to the original samples from the core PayTabs PHP SDK:
[PHP SDK samples](https://github.com/paytabscom/php-sdk/tree/main/Samples)

## Table of Contents

- [Creating Payment Requests](#creating-payment-requests)
- [Handling Responses](#handling-responses)
- [Using Multiple Profiles](#using-multiple-profiles)
- [Error Handling](#error-handling)
- [Advanced Usage](#advanced-usage)

## Creating Payment Requests

### Hosted Payment Page

The most common use case is creating a hosted payment page where customers are redirected to PayTabs to complete payment.

```php
use Paytabs\Laravel\Paytabs;
use Paytabs\Sdk\Enums\TranClass;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Request\Payload\PayloadsFactory;
use Paytabs\Sdk\Request\Payload\Parts\CustomerDetails;
use Paytabs\Sdk\Request\RequestsFactory;

// Create the payload
$payload = PayloadsFactory::createHostedPage();

// Add customer details
$payload
    ->buildTransaction(TranType::Sale, TranClass::Ecom)
    ->buildCart('order-001', 'AED', 99.5, 'Order Payment')
    ->buildCustomerDetails(
        CustomerDetails::init('Fname Lname', '+971500000000', 'customer@example.com')
            ->setAddress('UAE', 'State', 'City', 'Street', '12345')
    );

// Create the request
$request = RequestsFactory::createPaymentRequest($payload);

// Submit and get response
$response = Paytabs::submitRequest($request);

if ($response->isFailure()) {
    echo $response->getFailure()->code . ' - ' . $response->getFailure()->message;
    exit;
}

if ($response->isRedirect()) {
    $paymentUrl = $response->getRedirect()->redirect_url;
}
```

### Adding Shipping Details

```php
use Paytabs\Sdk\Request\Payload\PayloadsFactory;
use Paytabs\Sdk\Request\Payload\Parts\CustomerDetails;
use Paytabs\Sdk\Request\Payload\Parts\ShippingDetails;

$payload = PayloadsFactory::createHostedPage();

// Add customer details
$payload
    ->buildTransaction(TranType::Sale, TranClass::Ecom)
    ->buildCart('order-001', 'AED', 99.5, 'Order Payment');

$billing =  CustomerDetails::init('Fname Lname', '+971500000000', 'customer@example.com')
    ->setAddress('UAE', 'State', 'City', 'Street', '12345');

$shipping = ShippingDetails::init('Fname Lname', '+971500000000', 'shipping@example.com')
    ->setAddress('UAE', 'State', 'City', 'Street', '12345');

// Or Copy billing details to shipping details if they are the same
$shipping = ShippingDetails::init()->copyFrom($billing);

// Hide the Shipping section in the payment page unless essential details are required
$payload->buildHideShipping(true)
```

### Adding Frame Options

To fit the payment page inside iFrame:

```php
use Paytabs\Sdk\Enums\FramedTarget;
use Paytabs\Sdk\Request\Payload\Parts\Framed;

$payload->buildFramedObj(new Framed(true, FramedTarget::ReturnTop))
```

### Customizing the Payment page: Language, Alternative currency & Theme ID

```php
use Paytabs\Sdk\Enums\Language;

$payload->buildPaypageConfig(Language::Arabic, 'USD', $themeId)
```

## Handling Responses

### Checking Response Status

```php

use Paytabs\Laravel\Paytabs;
use Paytabs\Sdk\Response\Payload\PayloadInterface;
use Paytabs\Sdk\Response\Payload\Payloads\Payment\Completed;
use Paytabs\Sdk\Response\ResponseDirectInterface;

// Submit and get response
$response = Paytabs::submitRequest($request);

// Payment request failed (configuration issue, currency not found, ....)
if ($response->isFailure()) {
    echo $response->getFailure()->code.' - '.$response->getFailure()->message;
    exit;
}

// Payment request succeeded, but the user needs to be redirected to the payment page
if ($response->isRedirect()) {
    $paymentUrl = $response->getRedirect()->redirect_url;
}

// if not failed and not redirect, then it is a direct response
$response->isProcessed();

/**
 * @var ResponseDirectInterface $response
 * @var PayloadInterface $payload
 *                       The payload class is determined from the request itself
 *                       PaymentRequest expects a payload of Type: Completed
 */
$payload = $response->getPayloadMapped();

/** @var Completed $mappedPayload */
$mappedPayload = $response->getPayloadMapped();

$mappedPayload->isPaymentSuccessful();
$mappedPayload->isPaymentFailed();
$mappedPayload->cart_id;
```

### Handling Redirect Callbacks

After payment completion, PayTabs redirects back to your configured return URL. Handle the callback:

```php
use Paytabs\Laravel\Facades\Paytabs;

public function handleReturn()
{
    Log::info('PayTabs redirect received');

    try {
        $result = Paytabs::getResultProcessor()->handleRedirect();
    } catch (InvalidSignatureException $e1) {
        Log::alert($e1->getMessage());

        return redirect()->route('orders.index')
            ->withErrors(['message' => 'Invalid signature in payment response.']);
    }

    Log::info('PayTabs redirect processed', [
        'transactionResult' => $result,
    ]);

    $successful = $result->isTransactionSuccessful();

    if ($successful) {
        return redirect()->route('payment.success', $result->cartId);
    } else {
        return redirect()->route('payment.fail', $result->cartId);
    }
}
```

## Using Multiple Profiles

### Switching Profiles Dynamically

```php
use Paytabs\Laravel\Facades\Paytabs;
use Paytabs\Sdk\Profile\EndpointsFactory;
use Paytabs\Sdk\Profile\ProfilesFactory;

// Use Saudi Arabia profile for this transaction
$saudiProfile = ProfilesFactory::createProfile(
    EndpointsFactory::getKsaEndpoint(),
    config('paytabs.saudi.profile_id'),
    config('paytabs.saudi.server_key')
);

Paytabs::usingProfile($saudiProfile);

// Create and submit request
$response = Paytabs::submitRequest($request);

// Reset to default profile
Paytabs::usingDefaults();
```

### Using Credentials Directly

```php
use Paytabs\Laravel\Facades\Paytabs;
use Paytabs\Sdk\Profile\Endpoints\Egy;

// Use Egypt profile for this transaction
Paytabs::usingCredentials(
    config('paytabs.egypt.profile_id'),
    config('paytabs.egypt.server_key'),
    EndpointsFactory::getEgyptEndpoint()
);

// Create and submit request
$response = Paytabs::submitRequest($request);

// Reset to default
Paytabs::usingDefaults();
```

### Profile Resolver for Multi-Tenant Applications

Create a custom profile resolver to dynamically select profiles based on the transaction:

```php
<?php

namespace App\Services;

use Paytabs\Laravel\Contracts\ProfileResolverInterface;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Profile\ProfilesFactory;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

class MultiTenantProfileResolver implements ProfileResolverInterface
{
    public function resolveProfile(Ipn|Browser $transactionResult): ?Profile
    {
        // Get merchant ID from your database
        $merchantId = $transactionResult->cart_id; // Assuming cart_id contains merchant ID
        
        $merchant = Merchant::find($merchantId);
        
        if (!$merchant) {
            return null; // Use default profile
        }
        
        // Create profile for this merchant
        return ProfilesFactory::createProfile(
            $merchant->endpoint,
            $merchant->paytabs_profile_id,
            $merchant->paytabs_server_key
        );
    }
}
```

Configure in `config/paytabs.php`:

```php
'ipn_profile_resolver' => \App\Services\MultiTenantProfileResolver::class,
```

## Error Handling

### Try-Catch Pattern

```php
use Paytabs\Sdk\Exceptions\InvalidConfigurationException;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;

try {
    $response = Paytabs::submitRequest($request);
} catch (InvalidConfigurationException $e) {
    // Configuration error
    Log::error('PayTabs configuration error', ['error' => $e->getMessage()]);
    return back()->with('error', 'Payment service configuration error');
} catch (\Exception $e) {
    // General error
    Log::error('PayTabs error', ['error' => $e->getMessage()]);
    return back()->with('error', 'Payment processing failed');
}

// Callback/IPN

try {
    $result = Paytabs::getResultProcessor()->handleCallback(true);
} catch (InvalidSignatureException $e1) {
    Log::alert('Invalid signature in PayTabs callback', ['message' => $e1->getMessage()]);

    return response(['message' => 'Invalid signature'], 401);
} catch (IdempotencyException $e2) {
    Log::warning('Duplicate PayTabs callback', ['message' => $e2->getMessage()]);

    return response(['message' => 'Duplicate detected'], 200);
}
```

## Advanced Usage

### Invoice Payments

Create an invoice payment request:

```php
$payload = PayloadsFactory::createInvoice();

// Add invoice details
$invoice = new Invoice;
$invoice->setCharges(2, 1, 0, 10.5)
    ->setLineItems(
        new LineItems(
            LineItem::init()->setPrice(1, 3, 3),
            LineItem::init()->setPrice(2, 5, 10)
        )
    );
$payload
    ->buildCart('invoice-001', 'AED', 10.5, 'Invoice Description')
    ->buildInvoice($invoice);
```

### Query Transaction Status

Check the status of a previous transaction:

```php
use Paytabs\Sdk\Request\Payload\PayloadsFactory;
use Paytabs\Sdk\Request\Payload\Parts\Query;

$payload = PayloadsFactory::createTransactionQuery();
$payload->buildTransactionRef('transaction_reference_here');

$request = RequestsFactory::createTransactionQuery($payload);
$response = Paytabs::submitRequest($request);

if ($ptResponse->isFailure()) {
    return back()
        ->withErrors([
            'message' => 'Failed: '.$ptResponse->getFailure()->message,
        ]);
}

/** @var Completed $mapped */
$mapped = $ptResponse->getPayloadMapped();
```

### Refund Transaction

Process a refund:

```php
use Paytabs\Sdk\Request\Payload\PayloadsFactory;
use Paytabs\Sdk\Request\Payload\Parts\Refund;

$payload = PayloadsFactory::createRefund();
$payload
    ->buildCart('refund-001', 'AED', 11, 'Order Refund')
    ->buildTransactionRef('tran-ref-to-refund');

$request = RequestsFactory::createPaymentRequest($payload);
$response = Paytabs::submitRequest($request);

if ($ptResponse->isFailure()) {
    return back()
        ->withErrors([
            'message' => 'Payment failed: '.$ptResponse->getFailure()->message,
        ]);
}

/** @var Completed $mapped */
$mapped = $ptResponse->getPayloadMapped();

if ($mapped->isPaymentSuccessful()) {
    $refud_data = [
        'amount' => $mapped->tran_total,
        'status' => $mapped->payment_result->response_status,
        'tran_ref' => $mapped->tran_ref,
        'tran_type' => TranType::Refund->value,
    ];

    return redirect()->route('orders.index')
        ->with('message', 'Order refunded successfully!');
}

$msg = 'Refund failed: '.$mapped->payment_result->response_message;

return redirect()->route('orders.index')
    ->withErrors([
        'message' => $msg,
    ]);
```

## Best Practices

### 1. Store Transaction References

Always store the transaction reference & the trace code returned by PayTabs:

```php
$transactionRef = $response->tran_ref;
$traceCode = $response->trace || $response->ipn_trace;
```

### 2. Log All Payment Events

```php
use Illuminate\Support\Facades\Log;

Log::info('Payment initiated', [
    'order_id' => $order->id,
    'amount' => $order->total,
    'transaction_ref' => $response->tran_ref,
]);
```

### 3. Validate Callback Data

Always validate callback/ipn data and Signature before processing.


## Testing

### Using PayTabs Sandbox

Configure sandbox credentials in your `.env`:

```env
PAYTABS_ENDPOINT=ARE
PAYTABS_PROFILE_ID=sandbox_profile_id
PAYTABS_SERVER_KEY=sandbox_server_key
```

## Next Steps

- Read [IPN Handling](IPN_HANDLING.md) for webhook integration
- Review [PayTabs PHP SDK Documentation](https://deepwiki.com/paytabscom/php-sdk) for more advanced features
- Check [PayTabs Official Documentation](https://docs.paytabs.com/) for API reference
