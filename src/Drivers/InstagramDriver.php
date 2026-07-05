<?php

namespace Pr4w\SocialMetrics\Drivers;

use Pr4w\SocialMetrics\Concerns\ClassifiesGraphErrors;
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

    private const METRICS = 'views,reach,likes,comments,shares,saved,total_interactions';

    public function platform(): string
    {
        return 'instagram';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $context->config['graph_version'] ?? 'v21.0';

        // The Graph Batch endpoint allows up to 50 sub-requests per call.
        foreach (array_chunk($nativeIds, 50) as $chunk) {
            $batch = array_map(fn (string $id) => [
                'method' => 'GET',
                'relative_url' => "{$id}/insights?metric=" . self::METRICS,
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
                // $chunk is a clean 0-indexed list from array_chunk, so the
                // positional batch response maps back safely.
                $id = $chunk[$index];

                if (($sub['code'] ?? 0) !== 200) {
                    $result->addError($this->graphSubError($sub, MetricScope::Post, $id));

                    continue;
                }

                $body = json_decode($sub['body'] ?? '[]', true) ?: [];
                $i = $this->flatten($body['data'] ?? []);

                $result->addPost(new PostMetrics(
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
                ));
            }
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $context->config['graph_version'] ?? 'v21.0';
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
