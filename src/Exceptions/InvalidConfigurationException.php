<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Exceptions;

class InvalidConfigurationException extends \RuntimeException
{
    public static function missing(string $config): self
    {
        return new self(\sprintf(
            'PayTabs Laravel: Invalid value of config: [%s].',
            $config,
        ));
    }
}
