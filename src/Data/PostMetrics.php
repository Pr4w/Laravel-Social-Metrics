<?php

namespace Pr4w\SocialMetrics\Data;

use Carbon\CarbonImmutable;

/**
 * Normalized engagement for a single post.
 *
 * Null means the platform does not expose that metric (or returned nothing for
 * it, e.g. a creator who hid like counts). Zero is a real value. Keep that
 * distinction: do not coalesce null to 0 inside drivers.
 */
final readonly class PostMetrics
{
    public function __construct(
        public string $platform,
        public string $nativeId,
        public ?int $views = null,
        public ?int $likes = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $saves = null,
        public ?int $reach = null,
        public array $raw = [],
        public ?CarbonImmutable $fetchedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'native_id' => $this->nativeId,
            'views' => $this->views,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'saves' => $this->saves,
            'reach' => $this->reach,
            'fetched_at' => $this->fetchedAt?->toIso8601String(),
        ];
    }
}
