<?php

declare(strict_types=1);

namespace Paytabs\Laravel\Tests\Unit;

use Paytabs\Laravel\Contracts\IpnHandlerInterface;
use Paytabs\Laravel\Contracts\ProfileResolverInterface;
use Paytabs\Laravel\Exceptions\InvalidConfigurationException;
use Paytabs\Laravel\Services\PaytabsResolver;
use Paytabs\Laravel\Tests\TestCase;
use Paytabs\Sdk\Profile\Profile;
use Paytabs\Sdk\Profile\ProfilesFactory;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Browser;
use Paytabs\Sdk\Response\Payload\Payloads\Callbacks\Ipn;
use Paytabs\Sdk\Response\Responses\Webhook\AbstractTransactionResult;

final class PaytabsResolverTest extends TestCase
{
    public function test_no_profile_resolver_configured_returns_null(): void
    {
        $this->app['config']->set('paytabs.ipn_profile_resolver', null);

        $this->assertNull(PaytabsResolver::resolveProfile($this->app, new Ipn));
    }

    public function test_a_configured_profile_resolver_is_used(): void
    {
        $this->app['config']->set('paytabs.ipn_profile_resolver', TenantProfileResolver::class);

        $profile = PaytabsResolver::resolveProfile($this->app, new Ipn);

        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame(TenantProfileResolver::PROFILE_ID, $profile->getProfileId());
    }

    public function test_a_profile_resolver_returning_null_falls_through(): void
    {
        $this->app['config']->set('paytabs.ipn_profile_resolver', NullProfileResolver::class);

        $this->assertNull(PaytabsResolver::resolveProfile($this->app, new Ipn));
    }

    public function test_a_profile_resolver_not_implementing_the_contract_is_rejected(): void
    {
        $this->app['config']->set('paytabs.ipn_profile_resolver', NotAResolver::class);

        $this->expectException(InvalidConfigurationException::class);

        PaytabsResolver::resolveProfile($this->app, new Ipn);
    }

    public function test_a_profile_resolver_class_that_does_not_exist_is_rejected(): void
    {
        $this->app['config']->set('paytabs.ipn_profile_resolver', 'App\\Does\\Not\\Exist');

        $this->expectException(InvalidConfigurationException::class);

        PaytabsResolver::resolveProfile($this->app, new Ipn);
    }

    public function test_a_non_conforming_resolver_is_rejected_before_it_is_constructed(): void
    {
        $this->app['config']->set('paytabs.ipn_profile_resolver', ExplodingResolver::class);
        ExplodingResolver::$constructed = false;

        try {
            PaytabsResolver::resolveProfile($this->app, new Ipn);
            $this->fail('Expected the misconfiguration to be rejected.');
        } catch (InvalidConfigurationException) {
            // Constructor side effects must not run for a class that fails the contract check.
            $this->assertFalse(ExplodingResolver::$constructed);
        }
    }

    public function test_a_configured_ipn_handler_is_resolved(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', NoopHandler::class);

        $this->assertInstanceOf(NoopHandler::class, PaytabsResolver::resolveIpnHandler($this->app));
    }

    public function test_an_ipn_handler_not_implementing_the_contract_is_rejected(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', NotAResolver::class);

        $this->expectException(InvalidConfigurationException::class);

        PaytabsResolver::resolveIpnHandler($this->app);
    }

    public function test_an_ipn_handler_class_that_does_not_exist_is_rejected(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', 'App\\Does\\Not\\Exist');

        $this->expectException(InvalidConfigurationException::class);

        PaytabsResolver::resolveIpnHandler($this->app);
    }

    public function test_a_whitespace_only_handler_is_treated_as_unset(): void
    {
        $this->app['config']->set('paytabs.ipn_handler', '   ');

        $this->expectException(InvalidConfigurationException::class);

        PaytabsResolver::resolveIpnHandler($this->app);
    }
}

final class TenantProfileResolver implements ProfileResolverInterface
{
    public const PROFILE_ID = 987654;

    public function resolveProfile(Ipn|Browser $mappedPayload): ?Profile
    {
        return ProfilesFactory::createProfile('ARE', self::PROFILE_ID, 'TENANT-SERVER-KEY');
    }
}

final class NullProfileResolver implements ProfileResolverInterface
{
    public function resolveProfile(Ipn|Browser $mappedPayload): ?Profile
    {
        return null;
    }
}

final class ExplodingResolver
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }
}

final class NotAResolver {}

final class NoopHandler implements IpnHandlerInterface
{
    public function handleIpn(AbstractTransactionResult $transactionResult, Ipn $mappedPayload): void {}
}
