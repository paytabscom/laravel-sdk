<?php

namespace Paytabs\Laravel\Enums;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

enum IpnOutcome
{
    case Processed;
    case InvalidSignature;
    case Duplicate;
    case Stale;
    case HandlerFailed;
    case Disabled;

    public function defaultResponseMapper(): JsonResponse
    {
        switch ($this) {
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
                Log::warning('Unknown IPN outcome: '.$this->name);

                return Response::json(
                    ['status' => 'error', 'message' => 'Unknown IPN outcome'],
                    500,
                );
        }
    }
}
