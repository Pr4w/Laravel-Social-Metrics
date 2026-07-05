<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * YouTube Data API v3. Auth is chosen per call by which credential the context
 * carries — no config flag needed:
 *   - accessToken present  -> OAuth (Bearer). Account stats use channels.list?mine=true
 *     (no channel id needed).
 *   - otherwise            -> API key, sent as ?key=. Supplied per call as
 *     meta['api_key'] (callers with several keys pass one per ref/account).
 * The videos endpoint takes up to 50 ids per call.
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

        if (! $context->accessToken && ! $this->apiKey($context)) {
            return $result->addError(new MetricsError(
                'youtube', MetricScope::Post, null, ErrorReason::Configuration,
                'Missing YouTube credentials: supply an access token, or meta[api_key].',
            ));
        }

        foreach (array_chunk($nativeIds, 50) as $chunk) {
            $response = $this->apiGet($context, 'https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'statistics',
                'id' => implode(',', $chunk),
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
        $token = $context->accessToken;

        if ($token) {
            // OAuth path: the token identifies the channel via mine=true, so no
            // channel id or API key is needed.
            $response = Http::withToken($token)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'statistics',
                    'mine' => 'true',
                ]);
        } else {
            // Key-based path: public statistics for an explicit channel id.
            $key = $this->apiKey($context);
            $channelId = $context->meta['channel_id'] ?? $context->accountId ?? null;

            if (! $key || ! $channelId) {
                return $result->addError(new MetricsError(
                    'youtube', MetricScope::Account, null, ErrorReason::Configuration,
                    'Missing YouTube credentials: supply an access token (OAuth), or meta[api_key] plus a channel id (accountId or meta channel_id).',
                ));
            }

            $response = Http::get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'statistics',
                'id' => $channelId,
                'key' => $key,
            ]);
        }

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        if (! $response->json('items.0')) {
            return $result->addError(new MetricsError(
                'youtube', MetricScope::Account, null, ErrorReason::NotFound,
                $token
                    ? 'The authenticated Google account has no YouTube channel.'
                    : 'No channel returned for the given id.',
            ));
        }

        $stats = $response->json('items.0.statistics', []);

        return $result->setAccount(new AccountMetrics(
            platform: 'youtube',
            accountId: (string) ($context->accountId ?? $response->json('items.0.id') ?? 'me'),
            followers: $this->int($stats, 'subscriberCount'),
            posts: $this->int($stats, 'videoCount'),
            views: $this->int($stats, 'viewCount'),
            raw: $stats,
            fetchedAt: now()->toImmutable(),
        ));
    }

    /** The API key for the key-based path, supplied per call as meta['api_key']. */
    private function apiKey(MetricsContext $context): ?string
    {
        return $context->meta['api_key'] ?? null;
    }

    /**
     * Issue a Data API GET, choosing auth by which credential is present: an
     * OAuth token goes as a Bearer header; otherwise the API key is appended as
     * ?key=. Callers pass only the resource query (part, id, ...).
     */
    private function apiGet(MetricsContext $context, string $url, array $query): Response
    {
        if ($token = $context->accessToken) {
            return Http::withToken($token)->get($url, $query);
        }

        return Http::get($url, $query + ['key' => $this->apiKey($context)]);
    }

    /**
     * Google API errors carry a reason in error.errors[].reason. Key-specific
     * failures (keyInvalid/keyExpired) stay Configuration; a bare 401 on the
     * OAuth path is not matched here and falls through to the parent, which maps
     * it to NeedsReconnect. Verify reason strings against current docs.
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
