<?php

namespace Pr4w\SocialMetrics\Support;

/**
 * Everything a driver needs for one call, with no opinion about where any of it
 * came from. The caller (or a resolver) supplies the access token and any
 * per-account data; this package never resolves credentials itself.
 *
 * accessToken may be null for tokenless drivers (e.g. YouTube's key-based path;
 * it still accepts a token to unlock OAuth). accountId is the
 * caller's own label, echoed back into results and events. meta carries
 * per-account values a driver needs, e.g. ig_user_id, threads_user_id,
 * channel_id, organization_urn, is_person.
 */
final readonly class MetricsContext
{
    public function __construct(
        public string $platform,
        public ?string $accessToken = null,
        public string|int|null $accountId = null,
        public array $meta = [],
        public array $config = [],
    ) {}
}
