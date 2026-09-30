<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Paytabs\Laravel\Paytabs;
use Paytabs\Laravel\Responses\PaymentResponse;
use Paytabs\Sdk\Enums\TranClass;
use Paytabs\Sdk\Enums\TranType;
use Paytabs\Sdk\Request\Payload\PayloadsFactory;
use Paytabs\Sdk\Request\Payload\Parts\CustomerDetails;
use Paytabs\Sdk\Request\RequestsFactory;

class PaymentService
{
    public function __construct(
        private Paytabs $paytabs
    ) {}

    /**
     * Create a hosted payment page request.
     *
     * @param  string  $cart_id  The cart or order ID
     * @param  float  $amount  The transaction amount
     * @param  string  $currency  The transaction currency (e.g., 'AED')
     * @param  string  $description  Description of the transaction
     * @param  array  $customer  Customer details: ['name', 'email', 'phone', 'street', 'city', 'state', 'country', 'zip']
     * @param  TranType  $tran_type  Transaction type (default: TranType::Sale)
     * @param  TranClass  $tran_class  Transaction class (default: TranClass::Ecom)
     */
    public function createHostedPage(
        string $cart_id,
        float $amount,
        string $currency,
        string $description,
        array $customer,
        TranType $tran_type = TranType::Sale,
        TranClass $tran_class = TranClass::Ecom
    ): PaymentResponse {
        $payload = PayloadsFactory::createHostedPage();

        $customerDetails = CustomerDetails::init(
            $customer['name'] ?? '',
            $customer['phone'] ?? '',
            $customer['email'] ?? ''
        );

        if (isset($customer['country'])) {
            $customerDetails->setAddress(
                $customer['country'],
                $customer['state'] ?? '',
                $customer['city'] ?? '',
                $customer['street'] ?? '',
                $customer['zip'] ?? ''
            );
        }

        $payload
            ->buildTransaction($tran_type, $tran_class)
            ->buildCart($cart_id, $currency, $amount, $description)
            ->buildCustomerDetails($customerDetails);

        $request = RequestsFactory::createPaymentRequest($payload);

        $response = $this->paytabs->submitRequest($request);

        return new PaymentResponse($response);
    }
}
