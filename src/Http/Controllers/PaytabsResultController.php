<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
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
        if (! $this->shouldHandleIpn()) {
            Log::warning('IPN handling is disabled. See "paytabs.ipn_enabled" configuration value.');

            return Response::json();
        }

        $isVerified = $this->paytabsResultProcessor->dispatchIpn();

        if (! $isVerified) {
            return Response::json(
                ['status' => 'error', 'message' => 'Invalid Signature'],
                401,
            );
        }

        return Response::json(['status' => 'received']);
    }

    /**
     * Check if IPN handling is enabled in configuration.
     *
     * @return bool True if IPN handling is enabled
     */
    private function shouldHandleIpn(): bool
    {
        return (bool) Config::get('paytabs.ipn_enabled', true);
    }
}
