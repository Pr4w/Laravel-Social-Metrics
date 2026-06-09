<?php

namespace Pr4w\SocialMetrics;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class SocialMetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/social-metrics.php', 'social-metrics');

        $this->app->singleton(DriverManager::class, fn ($app) => new DriverManager($app));

        $this->app->singleton(MetricsOrchestrator::class, fn ($app) => new MetricsOrchestrator(
            $app->make(DriverManager::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->alias(MetricsOrchestrator::class, 'social-metrics');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/social-metrics.php' => config_path('social-metrics.php'),
            ], 'social-metrics-config');
        }
    }
}
