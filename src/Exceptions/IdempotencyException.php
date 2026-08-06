<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Exceptions;

/**
 * Thrown when the same IPN is delivered more than once within the idempotency window.
 */
class IdempotencyException extends IpnProcessingException {}
