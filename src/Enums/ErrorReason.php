<?php

namespace Pr4w\SocialMetrics\Enums;

/**
 * Fine-grained reason for a failure. Use this for logging detail; use
 * ErrorReason->category() (via MetricsError) for retry decisions.
 *
 * Unknown means the driver could not map the platform's error. These are not
 * retried (we will not blindly hammer something we do not understand) but are
 * surfaced so a mapping can be added later.
 */
enum ErrorReason: string
{
    case NeedsReconnect = 'needs_reconnect';
    case HttpError = 'http_error';
    case NotFound = 'not_found';
    case Unsupported = 'unsupported';
    case RateLimited = 'rate_limited';
    case DriverError = 'driver_error';
    case Configuration = 'configuration';
    case Unknown = 'unknown';

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::RateLimited, self::HttpError, self::DriverError => ErrorCategory::Temporary,
            self::NeedsReconnect => ErrorCategory::Reconnect,
            self::NotFound, self::Unsupported, self::Configuration => ErrorCategory::Permanent,
            self::Unknown => ErrorCategory::Unknown,
        };
    }
}
