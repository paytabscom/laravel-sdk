<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Enums\IpnOutcome;
use Paytabs\Laravel\Services\PaytabsResultProcessor;

class PaytabsResultController
{
    /**
     * Create a new controller instance.
     *
     * @param  PaytabsResultProcessor  $paytabsResultProcessor  The result processor service
     */
    public function __construct(
        private readonly PaytabsResultProcessor $paytabsResultProcessor,
    ) {}

    /**
     * Handle incoming PayTabs IPN (Instant Payment Notification) requests.
     *
     * @return JsonResponse JSON response with status
     */
    public function ipn(): JsonResponse
    {
        $ipnHandlerEnabled = (bool) Config::get('paytabs.ipn_enabled', true);
        if (! $ipnHandlerEnabled) {
            Log::debug('IPN handling is disabled. See "paytabs.ipn_enabled" configuration value.');

            return IpnOutcome::Disabled->toResponse();
        }

        $ipnOutcome = $this->paytabsResultProcessor->dispatchIpn();

        return $ipnOutcome->toResponse();
    }
}
