<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use IpnOutcome;
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
            Log::warning('IPN handling is disabled. See "paytabs.ipn_enabled" configuration value.');

            return Response::json(['IPN handler disabled'], 200);
        }

        $ipnOutcome = $this->paytabsResultProcessor->dispatchIpn();

        return $this->defaultIpnOutcomeResponse($ipnOutcome);
    }

    public function defaultIpnOutcomeResponse(IpnOutcome $ipnOutcome): JsonResponse
    {
        switch ($ipnOutcome) {
            case IpnOutcome::Processed:
                return Response::json(['status' => 'received']);

            case IpnOutcome::InvalidSignature:
                return Response::json(
                    ['status' => 'error', 'message' => 'Invalid Signature'],
                    401,
                );

            case IpnOutcome::Stale:
                return Response::json(
                    ['status' => 'error', 'message' => 'Stale IPN'],
                    200,
                );

            case IpnOutcome::Duplicate:
                return Response::json(
                    ['status' => 'error', 'message' => 'Duplicate IPN'],
                    200,
                );

            case IpnOutcome::HandlerFailed:
                $ack_exception = (bool) Config::get('paytabs.ack_on_handler_exception', false);
                if ($ack_exception) {
                    return Response::json(['status' => 'received']);
                }

                return Response::json(
                    ['status' => 'error', 'message' => 'IPN Handler Failed'],
                    500,
                );

            case IpnOutcome::Disabled:
                return Response::json(
                    ['status' => 'error', 'message' => 'IPN Handling Disabled'],
                    200,
                );

            default:
                Log::warning('Unknown IPN outcome: '.$ipnOutcome->name);

                return Response::json(
                    ['status' => 'error', 'message' => 'Unknown IPN outcome'],
                    500,
                );
        }
    }
}
