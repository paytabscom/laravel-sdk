<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Feature;

use Illuminate\Http\Request;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Exceptions\InvalidSignatureException;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;

final class HandleRedirectTest extends TestCase
{
    public function test_a_signed_browser_callback_is_verified(): void
    {
        $result = $this->processorFor($this->signedBrowserRequest())->handleRedirect();

        $this->assertInstanceOf(Browser::class, $result);
        $this->assertSame('TST2000000000001', $result->tranRef);
        $this->assertSame('order-001', $result->cartId);
        $this->assertTrue($result->isTransactionSuccessful());
    }

    public function test_a_declined_transaction_is_reported_as_unsuccessful(): void
    {
        $result = $this->processorFor(
            $this->signedBrowserRequest(['respStatus' => 'D', 'respMessage' => 'Declined'])
        )->handleRedirect();

        $this->assertFalse($result->isTransactionSuccessful());
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->processorFor($this->signedBrowserRequest([], [], 'deadbeef'))->handleRedirect();
    }

    public function test_a_tampered_field_invalidates_the_signature(): void
    {
        $fields = $this->browserFields();
        $signature = $this->browserSignature($fields);

        // Signature covers the original cart, so swapping it must not verify.
        $fields['cartId'] = 'order-tampered';
        $fields['signature'] = $signature;

        $this->expectException(InvalidSignatureException::class);

        $this->processorFor(Request::create('/paytabs/return', 'POST', $fields))->handleRedirect();
    }

    public function test_an_empty_post_body_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->processorFor(Request::create('/paytabs/return', 'POST'))->handleRedirect();
    }

    public function test_merchant_return_url_params_break_the_signature_when_undeclared(): void
    {
        $fields = $this->browserFields();
        $fields['signature'] = $this->browserSignature($fields);
        $fields['order'] = '123';

        $this->expectException(InvalidSignatureException::class);

        $this->processorFor(Request::create('/paytabs/return', 'POST', $fields))->handleRedirect();
    }

    public function test_declared_local_params_are_excluded_from_the_signature(): void
    {
        $this->app['config']->set('paytabs.browser_local_params', ['order']);

        $fields = $this->browserFields();
        $fields['signature'] = $this->browserSignature($fields);
        $fields['order'] = '123';

        $result = $this->processorFor(Request::create('/paytabs/return', 'POST', $fields))->handleRedirect();

        $this->assertSame('TST2000000000001', $result->tranRef);
    }

    public function test_array_fields_do_not_corrupt_the_signature(): void
    {
        $fields = $this->browserFields();
        $fields['signature'] = $this->browserSignature($fields);
        // Casting this to string would yield "Array" and silently break verification.
        $fields['extra'] = ['a', 'b'];

        $result = $this->processorFor(Request::create('/paytabs/return', 'POST', $fields))->handleRedirect();

        $this->assertSame('TST2000000000001', $result->tranRef);
    }

    public function test_a_json_ipn_body_is_rejected_by_the_redirect_handler(): void
    {
        // A JSON body populates no POST fields, so the SDK rejects it before mapping.
        $this->expectException(\InvalidArgumentException::class);

        $this->processorFor($this->signedIpnRequest())->handleRedirect();
    }
}
