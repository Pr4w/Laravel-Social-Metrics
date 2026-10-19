<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * Pinterest API v5, with the member's OAuth token (boards:read, pins:read).
 * nativeId is the Pin's id; accountId is a board's id: Pins are published to
 * boards, so an account is a board.
 *
 * Posts: GET /pins/{id}/analytics, one call per Pin (Pinterest's several-Pins
 * endpoint is a closed beta). Pinterest keeps 90 days of daily metrics, so
 * IMPRESSION (views) and SAVE (saves) are summed over the last 90 days, which is
 * the Pin's whole life until it is 90 days old; TOTAL_REACTIONS (likes) and
 * TOTAL_COMMENTS (comments) are lifetime totals. PIN_CLICK and OUTBOUND_CLICK
 * stay in raw; shares and reach are null. A 401 or a 429 fails every Pin the
 * same way, so the remaining ones are not requested.
 *
 * Account: GET /boards/{id}, the board's followers and Pins.
 *
 * The analytics are not available in Pinterest's Sandbox (where an app with
 * Trial access works). drivers.pinterest.api_url, or meta['api_url'] per call,
 * points the driver at another host.
 */
class PinterestDriver extends AbstractDriver
{
    private const API = 'https://api.pinterest.com/v5/';

    private const METRICS = ['IMPRESSION', 'SAVE', 'PIN_CLICK', 'OUTBOUND_CLICK', 'TOTAL_REACTIONS', 'TOTAL_COMMENTS'];

    /** HTTP statuses that will fail every remaining Pin the same way. */
    private const ACCOUNT_WIDE = [401, 429];

    public function platform(): string
    {
        return 'pinterest';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $halt = null;

        // Pinterest takes at most 90 days back, in UTC
        $range = [
            'start_date' => now()->utc()->subDays(89)->toDateString(),
            'end_date' => now()->utc()->toDateString(),
        ];

        foreach (array_values(array_unique(array_map('strval', $nativeIds))) as $id) {
            if ($halt) {
                $result->addError($this->httpError($halt, MetricScope::Post, $id));

                continue;
            }

            $response = $this->api($context)->get('pins/'.rawurlencode($id).'/analytics', $range + [
                'metric_types' => implode(',', self::METRICS),
            ]);

            if (! $response->successful()) {
                $result->addError($this->httpError($response, MetricScope::Post, $id));
                $halt = in_array($response->status(), self::ACCOUNT_WIDE, true) ? $response : null;

                continue;
            }

            // Keyed by app type: "all" without a split
            $body = (array) $response->json();
            $all = (array) ($body['all'] ?? (reset($body) ?: []));
            $summary = (array) ($all['summary_metrics'] ?? []);
            $lifetime = (array) ($all['lifetime_metrics'] ?? []);

            $result->addPost(new PostMetrics(
                platform: 'pinterest',
                nativeId: $id,
                views: $this->int($summary, 'IMPRESSION'),
                likes: $this->int($lifetime, 'TOTAL_REACTIONS') ?? $this->int($summary, 'TOTAL_REACTIONS'),
                comments: $this->int($lifetime, 'TOTAL_COMMENTS') ?? $this->int($summary, 'TOTAL_COMMENTS'),
                saves: $this->int($summary, 'SAVE'),
                raw: ['summary_metrics' => $summary, 'lifetime_metrics' => $lifetime] + $range,
                fetchedAt: now()->toImmutable(),
            ));
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $board = $context->meta['board_id'] ?? $context->accountId;

        if (! $board) {
            return $result->addError(new MetricsError(
                'pinterest', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing Pinterest board: pass its id as accountId.',
            ));
        }

        $response = $this->api($context)->get('boards/'.rawurlencode((string) $board));

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $data = (array) $response->json();

        return $result->setAccount(new AccountMetrics(
            platform: 'pinterest',
            accountId: (string) ($data['id'] ?? $board),
            followers: $this->int($data, 'follower_count'),
            posts: $this->int($data, 'pin_count'),
            raw: array_intersect_key($data, array_flip(['id', 'name', 'privacy', 'follower_count', 'pin_count', 'collaborator_count'])),
            fetchedAt: now()->toImmutable(),
        ));
    }

    /**
     * Errors are {code, message}. 401 code 2: the token was refused. 403: the
     * account can't read these analytics (some are business accounts only), or
     * the Pin isn't the token user's. 404: the Pin or board is gone.
     */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        $message = strtolower((string) ($body['message'] ?? ''));

        return match (true) {
            $status === 403 => ErrorReason::Permission,
            $status === 404, str_contains($message, 'not found') => ErrorReason::NotFound,
            default => parent::classifyError($status, $body),
        };
    }

    private function api(MetricsContext $context): PendingRequest
    {
        $url = $context->meta['api_url'] ?? $context->config['api_url'] ?? self::API;

        return Http::withToken((string) $context->accessToken)
            ->acceptJson()
            ->baseUrl(rtrim((string) $url, '/').'/');
    }
}
