<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * X (Twitter) API v2. Post metrics come from GET /2/tweets?ids=...&tweet.fields=
 * public_metrics, up to 100 ids per call, with the user's OAuth 2 token
 * (tweet.read). nativeId is the post id.
 *
 * Every post read is billed (pay-per-use), so ids are deduplicated, each one is
 * requested at most once per call, and nothing is retried here. Once a call
 * fails for the whole account (401, 402 out of credits, 429), the remaining
 * batches are not sent: their ids get the same error.
 *
 * A 200 can still be partial: ids missing from `data` are matched against the
 * `errors` array ("Could not find" = deleted or never existed).
 */
class TwitterDriver extends AbstractDriver
{
    private const BATCH = 100;

    /** HTTP statuses that will fail every remaining batch the same way. */
    private const ACCOUNT_WIDE = [401, 402, 429];

    public function platform(): string
    {
        return 'twitter';
    }

    public function supportsAccountMetrics(): bool
    {
        return false;
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $ids = array_values(array_unique(array_map('strval', $nativeIds)));
        $halt = null;

        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            if ($halt) {
                $this->failAll($chunk, $halt, $result);

                continue;
            }

            $response = Http::withToken($context->accessToken)->get('https://api.x.com/2/tweets', [
                'ids' => implode(',', $chunk),
                'tweet.fields' => 'public_metrics',
            ]);

            if (! $response->successful()) {
                $this->failAll($chunk, $response, $result);
                $halt = in_array($response->status(), self::ACCOUNT_WIDE, true) ? $response : null;

                continue;
            }

            $this->collect($chunk, $response, $result);
        }

        return $result;
    }

    /** Record the posts of a 200 response, and an error for each requested id it did not return. */
    private function collect(array $chunk, Response $response, DriverResult $result): void
    {
        $returned = [];

        foreach ($response->json('data', []) as $tweet) {
            $id = (string) $tweet['id'];
            $metrics = $tweet['public_metrics'] ?? [];
            $returned[$id] = true;

            $result->addPost(new PostMetrics(
                platform: 'twitter',
                nativeId: $id,
                views: $this->int($metrics, 'impression_count'),
                likes: $this->int($metrics, 'like_count'),
                comments: $this->int($metrics, 'reply_count'),
                shares: $this->shares($metrics),
                saves: $this->int($metrics, 'bookmark_count'),
                raw: $metrics,
                fetchedAt: now()->toImmutable(),
            ));
        }

        $problems = collect($response->json('errors', []))
            ->keyBy(fn (array $error) => (string) ($error['resource_id'] ?? $error['value'] ?? ''));

        foreach ($chunk as $id) {
            if (! isset($returned[$id])) {
                $result->addError($this->missingError($id, $problems->get($id)));
            }
        }
    }

    /** Retweets and quotes are both native shares. Null only when X reports neither. */
    private function shares(array $metrics): ?int
    {
        $retweets = $this->int($metrics, 'retweet_count');
        $quotes = $this->int($metrics, 'quote_count');

        return $retweets === null && $quotes === null ? null : (int) $retweets + (int) $quotes;
    }

    /**
     * An id a 200 response left out of `data`. X explains it in `errors`:
     * "Could not find tweet with ids: [...]" (resource-not-found) for a deleted
     * post, not-authorized-for-resource for a protected or suspended account's.
     */
    private function missingError(string $id, ?array $problem): MetricsError
    {
        $detail = (string) ($problem['detail'] ?? '');
        $type = (string) ($problem['type'] ?? '');

        $reason = match (true) {
            str_contains($detail, 'Could not find') || str_ends_with($type, '/resource-not-found') => ErrorReason::NotFound,
            str_ends_with($type, '/not-authorized-for-resource') => ErrorReason::Permission,
            default => ErrorReason::Unknown,
        };

        return new MetricsError(
            'twitter', MetricScope::Post, $id, $reason,
            $detail !== '' ? "twitter: {$detail}" : 'Post not returned by the API.',
            200,
            $problem ?? [],
        );
    }

    /** Give every id of a batch the error of the response that failed it. */
    private function failAll(array $chunk, Response $response, DriverResult $result): void
    {
        foreach ($chunk as $id) {
            $result->addError($this->httpError($response, MetricScope::Post, $id));
        }
    }

    /** 402 means the account ran out of pay-per-use credits: not retryable until topped up. */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        return $status === 402 ? ErrorReason::Configuration : parent::classifyError($status, $body);
    }

    /** X errors are RFC 7807 problems: {title, detail, type, status}. */
    protected function errorMessage(int $status, array $body): string
    {
        foreach (['detail', 'title'] as $key) {
            if (is_string($body[$key] ?? null) && $body[$key] !== '') {
                return "twitter: {$body[$key]}";
            }
        }

        return parent::errorMessage($status, $body);
    }
}
