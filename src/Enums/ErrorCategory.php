<?php

namespace Pr4w\SocialMetrics\Enums;

/**
 * Coarse error bucket for deciding what to do next.
 *
 * - Temporary: throttling, transport blips, server 5xx. Retry with backoff.
 * - Permanent: deleted/unsupported object, bad config. Never retry.
 * - Reconnect: token revoked or expired. The account needs re-auth.
 * - Unknown: the driver could not classify it. Do not retry; review and map it.
 */
enum ErrorCategory: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';
    case Reconnect = 'reconnect';
    case Unknown = 'unknown';
}
