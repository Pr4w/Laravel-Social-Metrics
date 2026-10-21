<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Pr4w\SocialMetrics\Concerns\PoolsRequests;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * Instagram professional accounts connected through "Instagram API with
 * Instagram Login", with no Facebook Page. Their tokens (IGAA...) only work on
 * graph.instagram.com, never on graph.facebook.com. The platform key matches
 * laravel-social-tokens' instagram_login connector.
 *
 * Same metrics, mapping and account call as InstagramDriver, on
 * graph.instagram.com. The Graph Batch endpoint is not documented there, so
 * post insights are one GET per media, fired in parallel like ThreadsDriver: a
 * failed media only fails its own id. A story id gets an error for that id
 * (likes, comments and saved do not exist for stories), never a crash.
 *
 * Account: the professional account's id (/me user_id), as accountId or
 * meta ig_user_id.
 */
class InstagramLoginDriver extends InstagramDriver
{
    use PoolsRequests;

    public function platform(): string
    {
        return 'instagram_login';
    }

    protected function graphHost(): string
    {
        return 'https://graph.instagram.com';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $ids = array_values(array_unique(array_map('strval', $nativeIds)));
        $url = "{$this->graphHost()}/{$this->graphVersion($context)}";
        $token = $context->accessToken;

        $responses = $this->pool($ids, function (Pool $pool, string $id) use ($url, $token) {
            $pool->as($id)->get("{$url}/{$id}/insights", [
                'metric' => self::METRICS,
                'access_token' => $token,
            ]);
        });

        foreach ($ids as $id) {
            $response = $responses[$id] ?? null;

            if (! ($response instanceof Response) || ! $response->successful()) {
                $result->addError($this->slotError($response, MetricScope::Post, $id, 'insights'));

                continue;
            }

            $result->addPost($this->mapPost($id, $this->flatten($response->json('data', []))));
        }

        return $result;
    }
}
