<?php

namespace Pr4w\SocialMetrics\Support;

/**
 * A reference to an account whose account-level metrics we want. Supply
 * accessToken (and meta) inline, or leave them null and let a resolver provide
 * them for this (platform, accountId).
 */
final readonly class AccountRef
{
    public function __construct(
        public string $platform,
        public string|int|null $accountId = null,
        public ?string $accessToken = null,
        public array $meta = [],
    ) {}

    public static function make(
        string $platform,
        string|int|null $accountId = null,
        ?string $accessToken = null,
        array $meta = [],
    ): self {
        return new self($platform, $accountId, $accessToken, $meta);
    }
}
