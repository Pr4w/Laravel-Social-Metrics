<?php

namespace Pr4w\SocialMetrics\Data;

use Illuminate\Support\Collection;

/**
 * The primary return value of a run. Synchronous, testable, no listeners
 * required. Events are fired in addition to this, never instead of it.
 */
final class MetricsResult
{
    /** @var Collection<int, PostMetrics> */
    public Collection $posts;

    /** @var Collection<int, AccountMetrics> */
    public Collection $accounts;

    /** @var Collection<int, MetricsError> */
    public Collection $errors;

    public function __construct()
    {
        $this->posts = collect();
        $this->accounts = collect();
        $this->errors = collect();
    }

    public function successful(): bool
    {
        return $this->errors->isEmpty();
    }

    public function failed(): bool
    {
        return $this->errors->isNotEmpty();
    }

    public function postFor(string $platform, string $nativeId): ?PostMetrics
    {
        return $this->posts->first(
            fn (PostMetrics $m) => $m->platform === $platform && $m->nativeId === $nativeId
        );
    }

    public function accountFor(string $platform, string $accountId): ?AccountMetrics
    {
        return $this->accounts->first(
            fn (AccountMetrics $m) => $m->platform === $platform && $m->accountId === $accountId
        );
    }
}
