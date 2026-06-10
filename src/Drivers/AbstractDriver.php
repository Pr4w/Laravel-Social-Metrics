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
        $error = is_array($response->json()) ? ($response->json('error') ?? null) : null;
        $reason = $this->reasonFor($response->status(), is_array($error) ? $error : null);

        $message = is_array($error) && ! empty($error['message'])
            ? "{$this->platform()}: {$error['message']}"
            : "HTTP {$response->status()} from {$this->platform()}.";

        return new MetricsError(
            $this->platform(),
            $scope,
            $nativeId,
            $reason,
            $message,
            $response->status(),
            $this->safeJson($response),
        );
    }

    /**
     * Build an error from one failed sub-response of a Graph batch call. The
     * body is a JSON string; we classify from the embedded Graph error so a
     * deleted post reads as not_found, not http_error.
     */
    protected function graphSubError(array $sub, MetricScope $scope, ?string $nativeId): MetricsError
    {
        $status = (int) ($sub['code'] ?? 0);
        $body = json_decode($sub['body'] ?? '[]', true) ?: [];
        $error = $body['error'] ?? null;

        $reason = $this->reasonFor($status, is_array($error) ? $error : null);

        $message = is_array($error) && ! empty($error['message'])
            ? "{$this->platform()}: {$error['message']}"
            : "Sub-request failed (HTTP {$status}).";

        return new MetricsError($this->platform(), $scope, $nativeId, $reason, $message, $status ?: null, $body);
    }

    /**
     * Pick an ErrorReason from an HTTP status and an optional Graph error object.
     * Distinguishes permanent failures (deleted object, revoked token) from
     * transient ones (throttling) so callers can retry the right things.
     */
    protected function reasonFor(int $status, ?array $graphError): ErrorReason
    {
        if (is_array($graphError) && ($mapped = $this->classifyGraphError($graphError)) !== null) {
            return $mapped;
        }

        return $status === 429 ? ErrorReason::RateLimited : ErrorReason::HttpError;
    }

    /** Map known Facebook/Instagram/Threads Graph error codes to a reason. */
    protected function classifyGraphError(array $error): ?ErrorReason
    {
        $code = (int) ($error['code'] ?? 0);
        $sub = (int) ($error['error_subcode'] ?? 0);

        // Token revoked / expired / session invalid -> needs reconnect.
        if (in_array($code, [102, 190, 463, 467], true)) {
            return ErrorReason::NeedsReconnect;
        }

        // Throttling / rate limiting.
        if (in_array($code, [4, 17, 32, 613], true) || $sub === 2446079) {
            return ErrorReason::RateLimited;
        }

        // Object does not exist, deleted, or unsupported get on the id -> permanent.
        if ($sub === 33 || in_array($code, [100, 803], true)) {
            return ErrorReason::NotFound;
        }

        return null;
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

    protected function safeJson(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['body' => $response->body()];
    }
}
