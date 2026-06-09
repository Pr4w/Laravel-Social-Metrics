<?php

namespace Pr4w\SocialMetrics\Events;

use Pr4w\SocialMetrics\Data\MetricsResult;

final readonly class MetricsRunCompleted
{
    public function __construct(
        public MetricsResult $result,
    ) {}
}
