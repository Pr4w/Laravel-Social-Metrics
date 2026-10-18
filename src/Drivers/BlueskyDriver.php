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
 * Bluesky, through the public AppView (public.api.bsky.app): counts are public,
 * so no token is needed. nativeId is the post's AT URI
 * (at://{did}/app.bsky.feed.post/{rkey}); accountId is the account's DID (or
 * handle).
 *
 * Bluesky exposes no views or reach: likes, replies (comments), reposts
 * (shares), and quotes and bookmarks in raw. getPosts takes up to 25 URIs
 * per call and leaves out the ones that no longer exist.
 */
class BlueskyDriver extends AbstractDriver
{
    private const APPVIEW = 'https://public.api.bsky.app/xrpc/';

    public function platform(): string
    {
        return 'bluesky';
    }

    public function requiresAccount(): bool
    {
        return false;
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;

        foreach (array_chunk(array_values(array_unique($nativeIds)), 25) as $chunk) {
            // XRPC wants the array as repeated keys (uris=a&uris=b), not uris[0]=a
            $query = implode('&', array_map(fn (string $uri) => 'uris='.rawurlencode($uri), $chunk));
            $response = Http::acceptJson()->get($this->appview($context).'app.bsky.feed.getPosts?'.$query);

            if (! $response->successful()) {
                $result->addError($this->httpError($response, MetricScope::Post));

                continue;
            }

            $seen = [];

            foreach ((array) $response->json('posts', []) as $post) {
                $uri = $post['uri'] ?? null;

                if (! is_string($uri) || ! in_array($uri, $chunk, true)) {
                    continue;
                }

                $seen[] = $uri;

                $result->addPost(new PostMetrics(
                    platform: 'bluesky',
                    nativeId: $uri,
                    likes: $this->int($post, 'likeCount'),
                    comments: $this->int($post, 'replyCount'),
                    shares: $this->int($post, 'repostCount'),
                    saves: $this->int($post, 'bookmarkCount'),
                    raw: array_intersect_key($post, array_flip(['likeCount', 'replyCount', 'repostCount', 'quoteCount', 'bookmarkCount', 'cid', 'indexedAt'])),
                    fetchedAt: now()->toImmutable(),
                ));
            }

            foreach (array_diff($chunk, $seen) as $missing) {
                $result->addError(new MetricsError(
                    'bluesky', MetricScope::Post, $missing, ErrorReason::NotFound,
                    'bluesky: the post no longer exists (deleted, or its account is gone).',
                ));
            }
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $actor = $context->accountId ?? $context->meta['did'] ?? null;

        if (! $actor) {
            return $result->addError(new MetricsError(
                'bluesky', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing Bluesky account: pass its DID (or handle) as accountId.',
            ));
        }

        $response = Http::acceptJson()->get($this->appview($context).'app.bsky.actor.getProfile', ['actor' => (string) $actor]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $profile = (array) $response->json();

        return $result->setAccount(new AccountMetrics(
            platform: 'bluesky',
            accountId: (string) ($profile['did'] ?? $actor),
            followers: $this->int($profile, 'followersCount'),
            following: $this->int($profile, 'followsCount'),
            posts: $this->int($profile, 'postsCount'),
            raw: array_intersect_key($profile, array_flip(['did', 'handle', 'followersCount', 'followsCount', 'postsCount'])),
            fetchedAt: now()->toImmutable(),
        ));
    }

    /**
     * XRPC errors are {error, message}: an unknown actor is a 400
     * InvalidRequest "Profile not found", a missing record NotFound.
     */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        $message = strtolower((string) ($body['message'] ?? ''));

        return match (true) {
            ($body['error'] ?? null) === 'NotFound', str_contains($message, 'not found') => ErrorReason::NotFound,
            ($body['error'] ?? null) === 'AccountTakedown', ($body['error'] ?? null) === 'AccountDeactivated' => ErrorReason::NotFound,
            default => parent::classifyError($status, $body),
        };
    }

    /** config['appview'] points at another AppView (a self-hosted one). */
    private function appview(MetricsContext $context): string
    {
        return rtrim((string) ($context->config['appview'] ?? self::APPVIEW), '/').'/';
    }
}
