<?php

namespace Pr4w\SocialMetrics\Support;

/**
 * A reference to a published post whose metrics we want. nativeId is the
 * platform id the insights endpoint accepts: media id (Instagram), video id
 * (YouTube, TikTok), or full URN (LinkedIn).
 *
 * accountId is your own account label (any string/int), used to group refs and
 * passed to a resolver. Supply accessToken (and meta) inline to skip the
 * resolver entirely, or leave them null and let a resolver provide them.
 */
final readonly class PostRef
{
    public function __construct(
        public string $platform,
        public string $nativeId,
        public string|int|null $accountId = null,
        public ?string $accessToken = null,
        public array $meta = [],
    ) {}

    public static function make(
        string $platform,
        string $nativeId,
        string|int|null $accountId = null,
        ?string $accessToken = null,
        array $meta = [],
    ): self {
        return new self($platform, $nativeId, $accountId, $accessToken, $meta);
    }
}
