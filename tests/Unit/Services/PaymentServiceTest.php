<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit\Services;

use Mockery;
use Paytabs\Laravel\Paytabs;
use Paytabs\Laravel\Services\PaymentService;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Request\AbstractRequest;
use Paytabs\Sdk\Response\ResponseDirectInterface;

class PaymentServiceTest extends TestCase
{
    private Paytabs $paytabsMock;
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paytabsMock = Mockery::mock(Paytabs::class);
        $this->service = new PaymentService($this->paytabsMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_can_create_hosted_page()
    {
        $responseMock = Mockery::mock(ResponseDirectInterface::class);
        $this->paytabsMock->shouldReceive('submitRequest')
            ->once()
            ->withArgs(function (AbstractRequest $request) {
                $body = $request->getPayloadObject()->getPayload()->getBody();

                return isset($body['cart_id']) && $body['cart_id'] === 'cart_1'
                    && isset($body['cart_amount']) && $body['cart_amount'] === 100.5
                    && isset($body['cart_currency']) && $body['cart_currency'] === 'AED'
                    && isset($body['tran_type']) && $body['tran_type'] === 'sale'
                    && isset($body['tran_class']) && $body['tran_class'] === 'ecom'
                    && isset($body['customer_details']['name']) && $body['customer_details']['name'] === 'John Doe';
            })
            ->andReturn($responseMock);

        $response = $this->service->createHostedPage(
            'cart_1',
            100.5,
            'AED',
            'Order 1',
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '+1234567890',
            ]
        );

        $this->assertSame($responseMock, $response->raw());
    }
}
