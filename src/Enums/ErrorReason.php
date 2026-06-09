<?php

namespace Pr4w\SocialMetrics\Enums;

enum ErrorReason: string
{
    case NeedsReconnect = 'needs_reconnect';
    case HttpError = 'http_error';
    case NotFound = 'not_found';
    case Unsupported = 'unsupported';
    case RateLimited = 'rate_limited';
    case DriverError = 'driver_error';
    case Configuration = 'configuration';
}
