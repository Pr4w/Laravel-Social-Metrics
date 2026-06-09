<?php

namespace Pr4w\SocialMetrics\Events;

use Pr4w\SocialMetrics\Data\AccountMetrics;

final readonly class AccountMetricsFetched
{
    public function __construct(
        public AccountMetrics $metrics,
        public string|int|null $accountId = null,
    ) {}
}
