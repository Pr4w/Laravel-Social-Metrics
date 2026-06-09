<?php

namespace Pr4w\SocialMetrics\Events;

use Pr4w\SocialMetrics\Data\PostMetrics;

final readonly class PostMetricsFetched
{
    public function __construct(
        public PostMetrics $metrics,
        public string|int|null $accountId = null,
    ) {}
}
