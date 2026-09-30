<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit\Responses;

use Mockery;
use Paytabs\Laravel\Responses\PaymentResponse;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Payloads\Failure;
use Paytabs\Sdk\Response\Payload\Payloads\Redirect;
use Paytabs\Sdk\Response\ResponseDirectInterface;

class PaymentResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_can_check_if_success()
    {
        $mock = Mockery::mock(ResponseDirectInterface::class);
        $mock->shouldReceive('isProcessed')->andReturn(true);
        $mock->shouldReceive('isFailure')->andReturn(false);

        $response = new PaymentResponse($mock);

        $this->assertTrue($response->isSuccess());
    }

    public function test_it_can_check_if_redirect_and_get_url()
    {
        $redirectMock = new Redirect();
        $redirectMock->redirect_url = 'https://secure.paytabs.com/payment/page/xyz';

        $mock = Mockery::mock(ResponseDirectInterface::class);
        $mock->shouldReceive('isRedirect')->andReturn(true);
        $mock->shouldReceive('getRedirect')->andReturn($redirectMock);

        $response = new PaymentResponse($mock);

        $this->assertTrue($response->isRedirect());
        $this->assertSame('https://secure.paytabs.com/payment/page/xyz', $response->getRedirectUrl());
    }

    public function test_it_can_handle_failure_and_get_message()
    {
        $failureMock = new Failure();
        $failureMock->message = 'Invalid credentials';
        $failureMock->code = 401;

        $mock = Mockery::mock(ResponseDirectInterface::class);
        $mock->shouldReceive('isFailure')->andReturn(true);
        $mock->shouldReceive('getFailure')->andReturn($failureMock);

        $response = new PaymentResponse($mock);

        $this->assertTrue($response->isFailure());
        $this->assertSame('Invalid credentials', $response->getMessage());
        $this->assertSame(401, $response->getCode());
    }

    public function test_raw_returns_original_response()
    {
        $mock = Mockery::mock(ResponseDirectInterface::class);

        $response = new PaymentResponse($mock);

        $this->assertSame($mock, $response->raw());
    }
}
