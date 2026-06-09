<?php

namespace Pr4w\SocialMetrics\Data;

use Illuminate\Support\Collection;

/**
 * What a single driver call returns. The orchestrator merges these into a
 * MetricsResult and fires events per item.
 */
final class DriverResult
{
    /** @var Collection<int, PostMetrics> */
    public Collection $posts;

    public ?AccountMetrics $account = null;

    /** @var Collection<int, MetricsError> */
    public Collection $errors;

    public function __construct()
    {
        $this->posts = collect();
        $this->errors = collect();
    }

    public function addPost(PostMetrics $metrics): self
    {
        $this->posts->push($metrics);

        return $this;
    }

    public function setAccount(AccountMetrics $metrics): self
    {
        $this->account = $metrics;

        return $this;
    }

    public function addError(MetricsError $error): self
    {
        $this->errors->push($error);

        return $this;
    }
}
