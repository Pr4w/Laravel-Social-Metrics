<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Concerns\ClassifiesGraphErrors;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * Facebook Pages via the Graph API. nativeId is the composite "{pageId}_{postId}"
 * you already store. Needs a Page access token (supply it via the resolver or
 * inline). Post engagement and insights are pulled in one batched call per post,
 * mirroring the Instagram driver.
 */
class FacebookDriver extends AbstractDriver
{
    use ClassifiesGraphErrors;

    // Counts via summaries, shares inline, impressions/reach via expanded insights.
    private const FIELDS = 'shares,comments.summary(true).limit(0),likes.summary(true).limit(0),insights.metric(post_impressions,post_impressions_unique)';

    public function platform(): string
    {
        return 'facebook';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $context->config['graph_version'] ?? 'v21.0';

        foreach (array_chunk($nativeIds, 50) as $chunk) {
            $batch = array_map(fn (string $id) => [
                'method' => 'GET',
                'relative_url' => "{$id}?fields=" . self::FIELDS,
            ], $chunk);

            $response = Http::asForm()->post("https://graph.facebook.com/{$version}/", [
                'access_token' => $context->accessToken,
                'batch' => json_encode($batch),
            ]);

            if (! $response->successful()) {
                $result->addError($this->httpError($response, MetricScope::Post));

                continue;
            }

            foreach ($response->json() as $index => $sub) {
                $id = $chunk[$index]; // array_chunk gives a clean 0-indexed list

                if (($sub['code'] ?? 0) !== 200) {
                    $result->addError($this->graphSubError($sub, MetricScope::Post, $id));

                    continue;
                }

                $body = json_decode($sub['body'] ?? '[]', true) ?: [];
                $insights = $this->flatten($body['insights']['data'] ?? []);

                $result->addPost(new PostMetrics(
                    platform: 'facebook',
                    nativeId: $id,
                    views: $insights['post_impressions'] ?? null,
                    likes: $this->int($body['likes']['summary'] ?? [], 'total_count'),
                    comments: $this->int($body['comments']['summary'] ?? [], 'total_count'),
                    shares: $this->int($body['shares'] ?? [], 'count'),
                    reach: $insights['post_impressions_unique'] ?? null,
                    raw: $body,
                    fetchedAt: now()->toImmutable(),
                ));
            }
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $context->config['graph_version'] ?? 'v21.0';
        $pageId = $context->meta['page_id'] ?? $context->accountId;

        if (! $pageId) {
            return $result->addError(new MetricsError(
                'facebook', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing Facebook page id (meta page_id or accountId).',
            ));
        }

        $response = Http::get("https://graph.facebook.com/{$version}/{$pageId}", [
            'fields' => 'followers_count,fan_count',
            'access_token' => $context->accessToken,
        ]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $data = $response->json() ?? [];

        return $result->setAccount(new AccountMetrics(
            platform: 'facebook',
            accountId: (string) $pageId,
            // followers_count is the modern field; fan_count (page likes) kept in raw.
            followers: $this->int($data, 'followers_count') ?? $this->int($data, 'fan_count'),
            raw: $data,
            fetchedAt: now()->toImmutable(),
        ));
    }
}
