<?php

namespace Pr4w\SocialMetrics\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Pr4w\SocialMetrics\SocialMetricsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SocialMetricsServiceProvider::class];
    }
}
