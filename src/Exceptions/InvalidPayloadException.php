<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Exceptions;

/**
 * Thrown when a callback payload cannot be mapped or is not of the expected type.
 */
class InvalidPayloadException extends IpnProcessingException {}
