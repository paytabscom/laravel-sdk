<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Enums;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;

enum IpnOutcome
{
    case Processed;
    case InvalidSignature;
    case InvalidPayload;
    case Duplicate;
    case Stale;
    case HandlerFailed;
    case Disabled;

    /**
     * Map the outcome to the JSON response returned to PayTabs.
     *
     * @return JsonResponse The response for this outcome
     */
    public function toResponse(): JsonResponse
    {
        // PayTabs abandons a delivery only on 403, 404 or 405. Any other failure status is retried,
        // so a rejection that can never succeed must answer 403 rather than a descriptive 4xx.
        [$statusCode, $payload] = match ($this) {
            self::Processed => [200, ['status' => 'received']],
            self::InvalidSignature => [403, ['status' => 'error', 'message' => 'Invalid Signature']],
            self::InvalidPayload => [403, ['status' => 'error', 'message' => 'Invalid Payload']],
            self::Stale => [200, ['status' => 'ignored', 'message' => 'Stale IPN']],
            self::Duplicate => [200, ['status' => 'ignored', 'message' => 'Duplicate IPN']],
            self::Disabled => [200, ['status' => 'ignored', 'message' => 'IPN Handling Disabled']],
            // A handler failure may succeed later, so this status must stay retryable.
            self::HandlerFailed => (bool) Config::get('paytabs.ack_on_handler_exception', false)
                ? [200, ['status' => 'received']]
                : [500, ['status' => 'error', 'message' => 'IPN Handler Failed']],
        };

        return Response::json($payload, $statusCode);
    }
}
