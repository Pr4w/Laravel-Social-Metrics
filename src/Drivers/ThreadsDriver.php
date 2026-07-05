<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Pr4w\SocialMetrics\Concerns\ClassifiesGraphErrors;
use Pr4w\SocialMetrics\Concerns\PoolsRequests;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;
use Illuminate\Support\Facades\Http;

/**
 * Threads (graph.threads.net, not graph.facebook.com). Per-post insights only,
 * so we fire one request per id in parallel via the pool.
 */
class ThreadsDriver extends AbstractDriver
{
    use ClassifiesGraphErrors;
    use PoolsRequests;

    private const POST_METRICS = 'views,likes,replies,reposts,quotes';

    public function platform(): string
    {
        return 'threads';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $token = $context->accessToken;

        $responses = $this->pool($nativeIds, function (Pool $pool, string $id) use ($token) {
            $pool->as($id)->get("https://graph.threads.net/v1.0/{$id}/insights", [
                'metric' => self::POST_METRICS,
                'access_token' => $token,
            ]);
        });

        foreach ($nativeIds as $id) {
            $response = $responses[$id] ?? null;

            if (! ($response instanceof Response) || ! $response->successful()) {
                $result->addError($this->slotError($response, MetricScope::Post, $id, 'insights'));

                continue;
            }

            $i = $this->flatten($response->json('data', []));

            $result->addPost(new PostMetrics(
                platform: 'threads',
                nativeId: $id,
                views: $i['views'] ?? null,
                likes: $i['likes'] ?? null,
                comments: $i['replies'] ?? null,   // replies = comments
                shares: $i['reposts'] ?? null,      // reposts = native shares
                raw: $i,                            // also carries quotes
                fetchedAt: now()->toImmutable(),
            ));
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $userId = $context->meta['threads_user_id'] ?? $context->accountId ?? null;

        if (! $userId) {
            return $result->addError(new MetricsError(
                'threads', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing Threads user id (pass it as accountId or meta threads_user_id).',
            ));
        }

        $response = Http::get("https://graph.threads.net/v1.0/{$userId}/threads_insights", [
            'metric' => 'followers_count',
            'access_token' => $context->accessToken,
        ]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $i = $this->flatten($response->json('data', []));

        return $result->setAccount(new AccountMetrics(
            platform: 'threads',
            accountId: (string) $userId,
            followers: $i['followers_count'] ?? null,
            raw: $i,
            fetchedAt: now()->toImmutable(),
        ));
    }
}
