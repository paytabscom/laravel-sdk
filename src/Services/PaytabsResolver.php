<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Laravel\Contracts\ProfileResolverInterface;
use Paytabs\Laravel\Exceptions\InvalidConfigurationException;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;

abstract class PaytabsResolver
{
    /**
     * Resolve a profile for the transaction result using configured resolver.
     *
     * @param  Container  $container  The Laravel container
     * @param  Ipn|Browser  $mappedPayload  The transaction result payload
     * @return Profile|null The resolved profile or null for default
     *
     * @throws \InvalidArgumentException If resolver class does not implement the interface
     */
    public static function resolveProfile(Container $container, Ipn|Browser $mappedPayload): ?Profile
    {
        $resolverClass = trim((string) Config::get('paytabs.ipn_profile_resolver', ''));

        if ($resolverClass === '') {
            return null;
        }

        if (! is_a($resolverClass, ProfileResolverInterface::class, true)) {
            Log::error(\sprintf(
                'PayTabs Profile resolver [%s] must implement %s.',
                $resolverClass,
                ProfileResolverInterface::class,
            ));
            throw InvalidConfigurationException::missing('paytabs.ipn_profile_resolver');
        }

        $resolver = $container->make($resolverClass);

        if (! $resolver instanceof ProfileResolverInterface) {
            Log::error(\sprintf(
                'PayTabs Profile resolver [%s] must implement %s.',
                $resolverClass,
                ProfileResolverInterface::class,
            ));
            throw InvalidConfigurationException::missing('paytabs.ipn_profile_resolver');
        }

        return $resolver->resolveProfile($mappedPayload);
    }

    /**
     * Resolve the IPN handler from configuration.
     *
     * @param  Container  $container  The Laravel container
     * @return IpnHandlerInterface The configured handler instance
     *
     * @throws InvalidConfigurationException If no handler is configured, or it does not implement the interface
     */
    public static function resolveIpnHandler(Container $container): IpnHandlerInterface
    {
        $handlerClass = trim((string) Config::get('paytabs.ipn_handler', ''));

        // Acknowledging an IPN without a handler would silently discard the payment notification.
        if ($handlerClass === '') {
            Log::error('No PayTabs IPN handler configured. See the "paytabs.ipn_handler" configuration value and the IpnHandlerInterface contract.');

            throw InvalidConfigurationException::missing('paytabs.ipn_handler');
        }

        if (! is_a($handlerClass, IpnHandlerInterface::class, true)) {
            Log::error(\sprintf(
                'PayTabs IPN resolver [%s] must implement %s.',
                $handlerClass,
                IpnHandlerInterface::class,
            ));
            throw InvalidConfigurationException::missing('paytabs.ipn_handler');
        }

        $handler = $container->make($handlerClass);

        if (! $handler instanceof IpnHandlerInterface) {
            Log::error(\sprintf(
                'PayTabs IPN handler [%s] must implement %s.',
                $handlerClass,
                IpnHandlerInterface::class,
            ));
            throw InvalidConfigurationException::missing('paytabs.ipn_handler');
        }

        return $handler;
    }
}
