<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * YouTube Data API v3. Key-based: public video and channel statistics need only
 * an API key, no OAuth account. The videos endpoint takes up to 50 ids per call.
 */
class YouTubeDriver extends AbstractDriver
{
    public function platform(): string
    {
        return 'youtube';
    }

    public function requiresAccount(): bool
    {
        return false;
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $key = $context->config['api_key'] ?? config('social-metrics.drivers.youtube.api_key');

        if (! $key) {
            return $result->addError(new MetricsError(
                'youtube', MetricScope::Post, null, ErrorReason::Configuration,
                'Missing social-metrics.drivers.youtube.api_key.',
            ));
        }

        foreach (array_chunk($nativeIds, 50) as $chunk) {
            $response = Http::get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'statistics',
                'id' => implode(',', $chunk),
                'key' => $key,
            ]);

            if (! $response->successful()) {
                $result->addError($this->httpError($response, MetricScope::Post));

                continue;
            }

            $seen = [];

            foreach ($response->json('items', []) as $item) {
                $stats = $item['statistics'] ?? [];
                $seen[] = $item['id'];

                $result->addPost(new PostMetrics(
                    platform: 'youtube',
                    nativeId: $item['id'],
                    views: $this->int($stats, 'viewCount'),
                    likes: $this->int($stats, 'likeCount'),
                    comments: $this->int($stats, 'commentCount'),
                    raw: $stats,
                    fetchedAt: now()->toImmutable(),
                ));
            }

            // Ids requested but not returned are deleted, private, or invalid.
            foreach (array_diff($chunk, $seen) as $missing) {
                $result->addError(new MetricsError(
                    'youtube', MetricScope::Post, $missing, ErrorReason::NotFound,
                    'Video not returned by the API (deleted, private, or invalid id).',
                ));
            }
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $key = $context->config['api_key'] ?? config('social-metrics.drivers.youtube.api_key');
        $channelId = $context->meta['channel_id'] ?? $context->accountId ?? $context->config['channel_id'] ?? config('social-metrics.drivers.youtube.channel_id');

        if (! $key || ! $channelId) {
            return $result->addError(new MetricsError(
                'youtube', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing youtube.api_key or channel_id (config or account profile channel_id).',
            ));
        }

        $response = Http::get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'statistics',
            'id' => $channelId,
            'key' => $key,
        ]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $stats = $response->json('items.0.statistics', []);

        return $result->setAccount(new AccountMetrics(
            platform: 'youtube',
            accountId: (string) $channelId,
            followers: $this->int($stats, 'subscriberCount'),
            posts: $this->int($stats, 'videoCount'),
            views: $this->int($stats, 'viewCount'),
            raw: $stats,
            fetchedAt: now()->toImmutable(),
        ));
    }

    /**
     * Google API errors carry a reason in error.errors[].reason. Auth problems
     * are key/config issues here (YouTube is key-based), not account reconnects.
     * Verify reason strings against current docs.
     */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        $reason = (string) data_get($body, 'error.errors.0.reason', '');

        return match (true) {
            in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded'], true) => ErrorReason::RateLimited,
            in_array($reason, ['videoNotFound', 'channelNotFound', 'playlistNotFound', 'notFound'], true) => ErrorReason::NotFound,
            in_array($reason, ['keyInvalid', 'keyExpired', 'ipRefererBlocked', 'forbidden'], true) => ErrorReason::Configuration,
            default => parent::classifyError($status, $body),
        };
    }
}
