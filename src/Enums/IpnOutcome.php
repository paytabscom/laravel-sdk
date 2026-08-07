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
        // Acknowledged deliveries must return 2xx, otherwise PayTabs keeps retrying.
        [$statusCode, $payload] = match ($this) {
            self::Processed => [200, ['status' => 'received']],
            self::InvalidSignature => [401, ['status' => 'error', 'message' => 'Invalid Signature']],
            // A malformed payload can never succeed on retry, so 4xx stops the retry cycle.
            self::InvalidPayload => [422, ['status' => 'error', 'message' => 'Invalid Payload']],
            self::Stale => [200, ['status' => 'ignored', 'message' => 'Stale IPN']],
            self::Duplicate => [200, ['status' => 'ignored', 'message' => 'Duplicate IPN']],
            self::Disabled => [200, ['status' => 'ignored', 'message' => 'IPN Handling Disabled']],
            self::HandlerFailed => (bool) Config::get('paytabs.ack_on_handler_exception', false)
                ? [200, ['status' => 'received']]
                : [500, ['status' => 'error', 'message' => 'IPN Handler Failed']],
        };

        return Response::json($payload, $statusCode);
    }
}
