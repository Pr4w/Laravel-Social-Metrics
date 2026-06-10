<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Response;
use Pr4w\SocialMetrics\Contracts\MetricsDriver;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;
use Throwable;

abstract class AbstractDriver implements MetricsDriver
{
    public function requiresAccount(): bool
    {
        return true;
    }

    public function supportsAccountMetrics(): bool
    {
        return true;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        return (new DriverResult)->addError(new MetricsError(
            $this->platform(),
            MetricScope::Account,
            null,
            ErrorReason::Unsupported,
            'Account metrics are not implemented for this driver.',
        ));
    }

    /** Cast a present, non-null array value to int; null otherwise (preserves unknown vs zero). */
    protected function int(array $data, string $key): ?int
    {
        return array_key_exists($key, $data) && $data[$key] !== null ? (int) $data[$key] : null;
    }

    /** Collapse Graph-style [{name, values:[{value}]}] insight rows into name => value. */
    protected function flatten(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$row['name']] = $row['values'][0]['value'] ?? null;
        }

        return $out;
    }

    protected function httpError(Response $response, MetricScope $scope, ?string $nativeId = null): MetricsError
    {
        $body = $this->safeJson($response);

        return new MetricsError(
            $this->platform(),
            $scope,
            $nativeId,
            $this->classifyError($response->status(), $body),
            $this->errorMessage($response->status(), $body),
            $response->status(),
            $body,
        );
    }

    /** Build an error from a pool slot that may be a Response or a Throwable. */
    protected function slotError(mixed $slot, MetricScope $scope, ?string $nativeId, string $what): MetricsError
    {
        if ($slot instanceof Response) {
            return $this->httpError($slot, $scope, $nativeId);
        }

        $message = $slot instanceof Throwable ? $slot->getMessage() : 'no response';

        return new MetricsError(
            $this->platform(),
            $scope,
            $nativeId,
            ErrorReason::DriverError,
            "{$what}: {$message}",
        );
    }

    /**
     * Map an HTTP status and decoded body to a reason. Override per platform,
     * since every vendor shapes errors differently. The default is status-only
     * and sends anything it does not recognize to Unknown, so unmapped failures
     * are flagged for review instead of silently bucketed as something else.
     */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        return match (true) {
            $status === 429 => ErrorReason::RateLimited,
            $status === 401 => ErrorReason::NeedsReconnect,
            $status === 404 => ErrorReason::NotFound,
            $status >= 500 => ErrorReason::HttpError,
            default => ErrorReason::Unknown,
        };
    }

    /** Pull the most useful human message across the common vendor error shapes. */
    protected function errorMessage(int $status, array $body): string
    {
        foreach (['error.message', 'message', 'error_description', 'error.errors.0.message'] as $path) {
            $message = data_get($body, $path);

            if (is_string($message) && $message !== '') {
                return "{$this->platform()}: {$message}";
            }
        }

        return "HTTP {$status} from {$this->platform()}.";
    }

    protected function safeJson(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['body' => $response->body()];
    }
}
