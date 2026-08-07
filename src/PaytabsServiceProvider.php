<?php

declare(strict_types=1);

namespace Paytabs\Laravel;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Paytabs\Laravel\Contracts\IpnIdempotencyGuardInterface;
use Paytabs\Laravel\Services\CacheIpnIdempotencyGuard;

class PaytabsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $configPublishPath = $this->app->basePath('config/paytabs.php');

        // Publish the configuration file
        $this->publishes([
            __DIR__.'/../config/paytabs.php' => $configPublishPath,
        ], 'paytabs-config');

        $loadRoutes = Config::get('paytabs.load_routes', true);

        if ($loadRoutes) {
            $this->loadRoutesFrom(__DIR__.'/../routes/paytabs.php');
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Merge the default configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/paytabs.php',
            'paytabs',
        );

        // Scoped binding isolates SDK state per request/job lifecycle.
        $this->app->scoped(Paytabs::class);
        $this->app->bind(IpnIdempotencyGuardInterface::class, CacheIpnIdempotencyGuard::class);
    }
}
