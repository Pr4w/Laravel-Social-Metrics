<?php

namespace Pr4w\SocialMetrics\Concerns;

use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * Graph Batch plumbing shared by the batch-based Meta drivers (Instagram,
 * Facebook). Pair it with ClassifiesGraphErrors — graphBatch() reports per-item
 * failures through graphSubError().
 */
trait FetchesGraphInsights
{
    /** The graph.facebook.com API version, overridable via driver config. */
    protected function graphVersion(MetricsContext $context): string
    {
        return $context->config['graph_version'] ?? 'v21.0';
    }

    /**
     * Run a Graph Batch of GET insight calls (max 50 sub-requests per HTTP call).
     * $requests maps each nativeId to its relative_url; for every 200 sub-response
     * $map($nativeId, $insights) returns the PostMetrics to record. Non-200 subs
     * and whole-batch failures are recorded as errors on $result.
     *
     * @param  array<string, string>  $requests  nativeId => relative_url
     * @param  callable(string, array): PostMetrics  $map
     */
    protected function graphBatch(string $version, ?string $token, array $requests, callable $map, DriverResult $result): void
    {
        foreach (array_chunk($requests, 50, true) as $chunk) {
            $ids = array_keys($chunk);

            $batch = array_map(
                fn (string $url) => ['method' => 'GET', 'relative_url' => $url],
                array_values($chunk),
            );

            $response = Http::asForm()->post("https://graph.facebook.com/{$version}/", [
                'access_token' => $token,
                'batch' => json_encode($batch),
            ]);

            if (! $response->successful()) {
                $result->addError($this->httpError($response, MetricScope::Post));

                continue;
            }

            foreach ($response->json() as $index => $sub) {
                // array_chunk preserves order, so the positional sub-response maps
                // back to the nativeId at the same index in this chunk.
                $id = $ids[$index];

                if (($sub['code'] ?? 0) !== 200) {
                    $result->addError($this->graphSubError($sub, MetricScope::Post, $id));

                    continue;
                }

                $body = json_decode($sub['body'] ?? '[]', true) ?: [];
                $result->addPost($map($id, $this->flatten($body['data'] ?? [])));
            }
        }
    }
}
