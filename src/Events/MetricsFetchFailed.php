<?php

namespace Pr4w\SocialMetrics\Events;

use Pr4w\SocialMetrics\Data\MetricsError;

final readonly class MetricsFetchFailed
{
    public function __construct(
        public MetricsError $error,
        public string|int|null $accountId = null,
    ) {}
}
