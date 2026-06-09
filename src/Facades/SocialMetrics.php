<?php

namespace Pr4w\SocialMetrics\Facades;

use Illuminate\Support\Facades\Facade;
use Pr4w\SocialMetrics\Contracts\MetricsDriver;
use Pr4w\SocialMetrics\Data\MetricsResult;

/**
 * @method static MetricsResult fetchPosts(iterable $refs, $resolver = null)
 * @method static MetricsResult fetchAccounts(iterable $accounts, $resolver = null)
 * @method static \Pr4w\SocialMetrics\MetricsOrchestrator resolveAccountsUsing($resolver)
 * @method static MetricsDriver driver(string $platform)
 * @method static \Pr4w\SocialMetrics\MetricsOrchestrator extend(string $platform, \Closure $callback)
 *
 * @see \Pr4w\SocialMetrics\MetricsOrchestrator
 */
class SocialMetrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'social-metrics';
    }
}
