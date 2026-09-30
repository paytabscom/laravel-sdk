<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit\Services;

use Mockery;
use Paytabs\Laravel\Paytabs;
use Paytabs\Laravel\Services\TransactionsService;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Request\AbstractRequest;
use Paytabs\Sdk\Response\ResponseDirectInterface;

class TransactionsServiceTest extends TestCase
{
    private Paytabs $paytabsMock;

    private TransactionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paytabsMock = Mockery::mock(Paytabs::class);
        $this->service = new TransactionsService($this->paytabsMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_can_query_transaction()
    {
        $responseMock = Mockery::mock(ResponseDirectInterface::class);
        $this->paytabsMock->shouldReceive('submitRequest')
            ->once()
            ->withArgs(function (AbstractRequest $request) {
                $body = $request->getPayloadObject()->getPayload()->getBody();

                return isset($body['tran_ref']) && $body['tran_ref'] === 'tran_123';
            })
            ->andReturn($responseMock);

        $response = $this->service->query('tran_123');

        $this->assertSame($responseMock, $response->raw());
    }

    public function test_it_can_capture_transaction()
    {
        $responseMock = Mockery::mock(ResponseDirectInterface::class);
        $this->paytabsMock->shouldReceive('submitRequest')
            ->once()
            ->withArgs(function (AbstractRequest $request) {
                $body = $request->getPayloadObject()->getPayload()->getBody();

                return isset($body['tran_ref']) && $body['tran_ref'] === 'tran_123'
                    && isset($body['tran_type']) && $body['tran_type'] === 'capture'
                    && isset($body['cart_id']) && $body['cart_id'] === 'cart_1'
                    && isset($body['cart_amount']) && $body['cart_amount'] === 100.5
                    && isset($body['cart_currency']) && $body['cart_currency'] === 'AED';
            })
            ->andReturn($responseMock);

        $response = $this->service->capture('tran_123', 'cart_1', 100.5, 'AED', 'Capture reason');

        $this->assertSame($responseMock, $response->raw());
    }

    public function test_it_can_refund_transaction()
    {
        $responseMock = Mockery::mock(ResponseDirectInterface::class);
        $this->paytabsMock->shouldReceive('submitRequest')
            ->once()
            ->withArgs(function (AbstractRequest $request) {
                $body = $request->getPayloadObject()->getPayload()->getBody();

                return isset($body['tran_ref']) && $body['tran_ref'] === 'tran_123'
                    && isset($body['tran_type']) && $body['tran_type'] === 'refund'
                    && isset($body['cart_id']) && $body['cart_id'] === 'cart_1'
                    && isset($body['cart_amount']) && $body['cart_amount'] === 50.0
                    && isset($body['cart_currency']) && $body['cart_currency'] === 'AED';
            })
            ->andReturn($responseMock);

        $response = $this->service->refund('tran_123', 'cart_1', 50.0, 'AED', 'Refund reason');

        $this->assertSame($responseMock, $response->raw());
    }

    public function test_it_can_void_transaction()
    {
        $responseMock = Mockery::mock(ResponseDirectInterface::class);
        $this->paytabsMock->shouldReceive('submitRequest')
            ->once()
            ->withArgs(function (AbstractRequest $request) {
                $body = $request->getPayloadObject()->getPayload()->getBody();

                return isset($body['tran_ref']) && $body['tran_ref'] === 'tran_123'
                    && isset($body['tran_type']) && $body['tran_type'] === 'void'
                    && isset($body['cart_id']) && $body['cart_id'] === 'cart_1'
                    && isset($body['cart_amount']) && $body['cart_amount'] === 0.0
                    && isset($body['cart_currency']) && $body['cart_currency'] === 'AED';
            })
            ->andReturn($responseMock);

        $response = $this->service->void('tran_123', 'cart_1', 0.0, 'AED', 'Void reason');

        $this->assertSame($responseMock, $response->raw());
    }
}
