<?php

namespace Pr4w\SocialMetrics\Drivers;

use Pr4w\SocialMetrics\Concerns\ClassifiesGraphErrors;
use Pr4w\SocialMetrics\Concerns\FetchesGraphInsights;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * Instagram via the Facebook Graph API. Post insights are fetched with the
 * Graph Batch endpoint: one HTTP call returns many media's insights. nativeId
 * is the media id (stored by Laravel-Social-Poster at publish time), so no
 * shortcode resolution is needed.
 */
class InstagramDriver extends AbstractDriver
{
    use ClassifiesGraphErrors;
    use FetchesGraphInsights;

    private const METRICS = 'views,reach,likes,comments,shares,saved,total_interactions';

    public function platform(): string
    {
        return 'instagram';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;

        $requests = [];

        foreach ($nativeIds as $id) {
            $requests[$id] = "{$id}/insights?metric=" . self::METRICS;
        }

        $this->graphBatch($this->graphVersion($context), $context->accessToken, $requests, fn (string $id, array $i) => new PostMetrics(
            platform: 'instagram',
            nativeId: $id,
            views: $i['views'] ?? null,
            likes: $i['likes'] ?? null,
            comments: $i['comments'] ?? null,
            shares: $i['shares'] ?? null,
            saves: $i['saved'] ?? null,
            reach: $i['reach'] ?? null,
            raw: $i,
            fetchedAt: now()->toImmutable(),
        ), $result);

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $this->graphVersion($context);
        $igUserId = $context->meta['ig_user_id'] ?? $context->accountId ?? null;

        if (! $igUserId) {
            return $result->addError(new MetricsError(
                'instagram', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing IG business user id (pass it as accountId or meta ig_user_id).',
            ));
        }

        $response = Http::get("https://graph.facebook.com/{$version}/{$igUserId}", [
            'fields' => 'followers_count,follows_count,media_count',
            'access_token' => $context->accessToken,
        ]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $data = $response->json() ?? [];

        return $result->setAccount(new AccountMetrics(
            platform: 'instagram',
            accountId: (string) $igUserId,
            followers: $this->int($data, 'followers_count'),
            following: $this->int($data, 'follows_count'),
            posts: $this->int($data, 'media_count'),
            raw: $data,
            fetchedAt: now()->toImmutable(),
        ));
    }
}
