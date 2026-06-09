<?php

namespace Pr4w\SocialMetrics\Contracts;

use Pr4w\SocialMetrics\Support\ResolvedAccount;

/**
 * The single auth seam. Implement this (or pass an equivalent closure) to tell
 * the package how to turn one of your account labels into an access token. The
 * package never assumes where tokens live.
 */
interface TokenResolver
{
    public function resolve(string $platform, string|int|null $accountId): ?ResolvedAccount;
}
