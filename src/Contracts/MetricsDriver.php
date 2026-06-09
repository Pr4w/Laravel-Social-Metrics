<?php

namespace Pr4w\SocialMetrics\Contracts;

use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Support\MetricsContext;

interface MetricsDriver
{
    public function platform(): string;

    /** Whether this driver needs an account access token (false for key-based, e.g. YouTube). */
    public function requiresAccount(): bool;

    public function supportsAccountMetrics(): bool;

    /** @param array<int, string> $nativeIds */
    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult;

    public function fetchAccountMetrics(MetricsContext $context): DriverResult;
}
