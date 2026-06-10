<?php

namespace Pr4w\SocialMetrics\Concerns;

use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;

/**
 * Error classification shared by the Meta Graph drivers (Instagram, Facebook,
 * Threads), which all return the same {error: {code, error_subcode, ...}} shape.
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

            // Throttling / rate limiting.
            if (in_array($code, [4, 17, 32, 613], true) || $sub === 2446079) {
                return ErrorReason::RateLimited;
            }

            // Object does not exist / deleted / unsupported get on the id.
            if ($sub === 33 || in_array($code, [100, 803], true)) {
                return ErrorReason::NotFound;
            }
        }

        return parent::classifyError($status, $body);
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
