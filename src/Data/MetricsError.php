<?php

namespace Pr4w\SocialMetrics\Data;

use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;

/**
 * A structured, non-fatal failure. Drivers return these inside a DriverResult
 * instead of throwing, so one dead post never sinks the rest of the run.
 */
final readonly class MetricsError
{
    public function __construct(
        public string $platform,
        public MetricScope $scope,
        public ?string $nativeId,
        public ErrorReason $reason,
        public string $message,
        public ?int $httpStatus = null,
        public array $raw = [],
    ) {}

    /**
     * Whether retrying later could plausibly succeed. Throttling and transport
     * blips are retryable; a deleted post, revoked token, unsupported metric or
     * misconfiguration are not. An http_error is only retryable on a 5xx (or
     * unknown status), since a classified 4xx is treated as permanent.
     */
    public function retryable(): bool
    {
        return match ($this->reason) {
            ErrorReason::RateLimited, ErrorReason::DriverError => true,
            ErrorReason::HttpError => $this->httpStatus === null || $this->httpStatus >= 500,
            default => false,
        };
    }

    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'scope' => $this->scope->value,
            'native_id' => $this->nativeId,
            'reason' => $this->reason->value,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
        ];
    }
}
