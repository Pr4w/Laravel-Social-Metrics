<?php

namespace Pr4w\SocialMetrics\Data;

use Carbon\CarbonImmutable;

/**
 * Normalized account-level metrics. Same null-vs-zero rule as PostMetrics.
 * `views` is lifetime/total where the platform exposes it (e.g. YouTube).
 */
final readonly class AccountMetrics
{
    public function __construct(
        public string $platform,
        public string $accountId,
        public ?int $followers = null,
        public ?int $following = null,
        public ?int $posts = null,
        public ?int $views = null,
        public array $raw = [],
        public ?CarbonImmutable $fetchedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'account_id' => $this->accountId,
            'followers' => $this->followers,
            'following' => $this->following,
            'posts' => $this->posts,
            'views' => $this->views,
            'fetched_at' => $this->fetchedAt?->toIso8601String(),
        ];
    }
}
