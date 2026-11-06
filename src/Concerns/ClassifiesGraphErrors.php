<?php

namespace Pr4w\SocialMetrics\Concerns;

use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;

/**
 * Error classification shared by the Meta Graph drivers (Instagram, Instagram
 * Login, Facebook, Threads), which all return the same
 * {error: {code, error_subcode, ...}} shape.
 * Anything not recognized falls through to the generic status-based default.
 *
 * Codes from the Graph API error reference; verify against current docs.
 */
trait ClassifiesGraphErrors
{
    protected function classifyError(int $status, array $body): ErrorReason
    {
        $error = $body['error'] ?? null;

        if (is_array($error)) {
            $code = (int) ($error['code'] ?? 0);
            $sub = (int) ($error['error_subcode'] ?? 0);

            // Token revoked / expired / session invalid.
            if (in_array($code, [102, 190, 463, 467], true)) {
                return ErrorReason::NeedsReconnect;
            }

            // Throttling / rate limiting. 80000-80014 are the business use case
            // limits (80002: too many calls to this Instagram account).
            if (in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014) || $sub === 2446079) {
                return ErrorReason::RateLimited;
            }

            // Object does not exist / deleted / unsupported get on the id.
            if ($sub === 33 || $code === 803 || ($code === 100 && $this->missingObject((string) ($error['message'] ?? '')))) {
                return ErrorReason::NotFound;
            }

            // Any other #100 is an invalid request (e.g. "The value must be a
            // valid insights metric" after a metric is retired): the object
            // exists, so it must not be reported as gone.
            if ($code === 100) {
                return ErrorReason::Configuration;
            }

            // Permission not granted or removed: #10 (API Permission Denied)
            // and the 200-299 API Permission range. Checked after the reconnect,
            // rate-limit and missing-object codes, none of which fall in here.
            // A #10 message ("Object does not exist, cannot be loaded due to
            // missing permission...") is not read as NotFound: it is just as
            // likely a live post read with the wrong token.
            if ($code === 10 || ($code >= 200 && $code <= 299)) {
                return ErrorReason::Permission;
            }
        }

        return parent::classifyError($status, $body);
    }

    private function missingObject(string $message): bool
    {
        return str_contains($message, 'does not exist') || str_contains($message, 'Unsupported get request');
    }

    /**
     * Build an error from one failed sub-response of a Graph batch call. The
     * body is a JSON string, so we decode and classify the embedded error.
     */
    protected function graphSubError(array $sub, MetricScope $scope, ?string $nativeId): MetricsError
    {
        $status = (int) ($sub['code'] ?? 0);
        $body = json_decode($sub['body'] ?? '[]', true) ?: [];

        return new MetricsError(
            $this->platform(),
            $scope,
            $nativeId,
            $this->classifyError($status, $body),
            $this->errorMessage($status, $body),
            $status ?: null,
            $body,
        );
    }
}
