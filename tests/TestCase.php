<?php

namespace Pr4w\SocialMetrics\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Pr4w\SocialMetrics\SocialMetricsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // A request no fake answers must fail the test, never reach a real API.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [SocialMetricsServiceProvider::class];
    }
}
