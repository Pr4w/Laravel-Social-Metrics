<?php

namespace Pr4w\SocialMetrics;

use Illuminate\Support\Manager;
use Pr4w\SocialMetrics\Contracts\MetricsDriver;
use Pr4w\SocialMetrics\Drivers\FacebookDriver;
use Pr4w\SocialMetrics\Drivers\InstagramDriver;
use Pr4w\SocialMetrics\Drivers\LinkedInDriver;
use Pr4w\SocialMetrics\Drivers\ThreadsDriver;
use Pr4w\SocialMetrics\Drivers\TikTokDriver;
use Pr4w\SocialMetrics\Drivers\YouTubeDriver;
use RuntimeException;

/**
 * Resolves platform drivers. Register your own with
 * SocialMetrics::extend('x', fn () => new XDriver()).
 */
class DriverManager extends Manager
{
    public function getDefaultDriver(): string
    {
        throw new RuntimeException('No default metrics driver. Specify a platform, e.g. SocialMetrics::driver("instagram").');
    }

    protected function createInstagramDriver(): MetricsDriver
    {
        return new InstagramDriver;
    }

    protected function createFacebookDriver(): MetricsDriver
    {
        return new FacebookDriver;
    }

    protected function createThreadsDriver(): MetricsDriver
    {
        return new ThreadsDriver;
    }

    protected function createTiktokDriver(): MetricsDriver
    {
        return new TikTokDriver;
    }

    protected function createYoutubeDriver(): MetricsDriver
    {
        return new YouTubeDriver;
    }

    protected function createLinkedinDriver(): MetricsDriver
    {
        return new LinkedInDriver;
    }
}
