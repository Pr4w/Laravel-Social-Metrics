<?php

namespace Pr4w\SocialMetrics\Support;

/**
 * What a token resolver returns for a given (platform, accountId): a usable
 * access token plus any per-account meta the driver needs. Return null from a
 * resolver to signal the account cannot be used right now (treated as
 * needs_reconnect, the run continues).
 */
final readonly class ResolvedAccount
{
    public function __construct(
        public ?string $accessToken = null,
        public array $meta = [],
    ) {}
}
