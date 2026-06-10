<?php

namespace Pr4w\SocialMetrics\Data;

use Pr4w\SocialMetrics\Enums\ErrorCategory;
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

    public function category(): ErrorCategory
    {
        return $this->reason->category();
    }

    /**
     * Whether retrying later could plausibly succeed: true only for the
     * Temporary category (throttling, transport, 5xx). Permanent, Reconnect and
     * Unknown all return false.
     */
    public function retryable(): bool
    {
        return $this->category() === ErrorCategory::Temporary;
    }

    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'scope' => $this->scope->value,
            'native_id' => $this->nativeId,
            'reason' => $this->reason->value,
            'category' => $this->category()->value,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
        ];
    }
}
