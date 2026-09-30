<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit\Responses;

use Paytabs\Laravel\Responses\TransactionResponse;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Response\Payload\Parts\PaymentResult;
use Paytabs\Sdk\Response\Payload\Payloads\Payment\Completed;
use Paytabs\Sdk\Response\ResponseDirectInterface;

class TransactionResponseTest extends TestCase
{
    public function test_it_wraps_raw_response_correctly()
    {
        $rawMock = $this->createMock(ResponseDirectInterface::class);

        $rawMock->expects($this->once())
            ->method('isProcessed')
            ->willReturn(true);

        $rawMock->expects($this->any())
            ->method('isFailure')
            ->willReturn(false);

        $payloadMock = $this->createMock(Completed::class);
        $paymentResultMock = $this->createMock(PaymentResult::class);
        $paymentResultMock->response_status = 'A';
        $paymentResultMock->response_message = 'Approved';
        $payloadMock->payment_result = $paymentResultMock;
        $payloadMock->tran_ref = 'tran_123';
        $payloadMock->cart_id = 'cart_1';
        $payloadMock->cart_amount = 100.5;
        $payloadMock->cart_currency = 'AED';
        $payloadMock->tran_type = 'Sale';

        $rawMock->expects($this->any())
            ->method('getPayloadMapped')
            ->willReturn($payloadMock);

        $response = new TransactionResponse($rawMock);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->isAuthorized());
        $this->assertEquals('tran_123', $response->getTranRef());
        $this->assertEquals('Approved', $response->getMessage());
        $this->assertEquals('cart_1', $response->getCartId());
        $this->assertEquals(100.5, $response->getCartAmount());
        $this->assertEquals('AED', $response->getCartCurrency());
        $this->assertEquals('Sale', $response->getTranType());
        $this->assertEquals('A', $response->getPaymentResult()->response_status);
        $this->assertSame($rawMock, $response->raw());
    }
}
