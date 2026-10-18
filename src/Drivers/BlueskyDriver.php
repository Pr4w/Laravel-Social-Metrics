<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Pool;
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
 * Bluesky, through the public AppView (public.api.bsky.app): counts are public,
 * so no token is needed. nativeId is the post's AT URI in its DID form
 * (at://{did}/app.bsky.feed.post/{rkey}); a handle is not stable, so a URI
 * built on one is rejected as malformed before any call. The account is
 * meta['did'] ?? accountId, a DID or a handle.
 *
 * Bluesky exposes no views or reach: likes, replies (comments), reposts
 * (shares), bookmarks (saves), and quotes in raw. getPosts takes up to 25 URIs
 * per call and silently leaves out a post that was deleted, but also every
 * post of an account that is deactivated or taken down, which can both come
 * back. For missing posts only, the author's profile tells the two apart.
 */
class BlueskyDriver extends AbstractDriver
{
    private const APPVIEW = 'https://public.api.bsky.app/xrpc/';

    private const BATCH = 25;

    private const POST_URI = '#^at://did:[a-z0-9]+:[^/]+/app\.bsky\.feed\.post/[^/]+$#';

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
        $uris = [];
        $missing = [];

        // One malformed URI would fail its whole getPosts call, so they are
        // caught here and never sent.
        foreach (array_unique(array_map('strval', $nativeIds)) as $uri) {
            if (preg_match(self::POST_URI, $uri)) {
                $uris[] = $uri;
            } else {
                $result->addError(new MetricsError(
                    'bluesky', MetricScope::Post, $uri, ErrorReason::Configuration,
                    'Malformed Bluesky post id: expected the AT URI at://{did}/app.bsky.feed.post/{rkey} (a handle is not stable: store the DID form).',
                ));
            }
        }

        foreach (array_chunk($uris, self::BATCH) as $chunk) {
            // XRPC wants the array as repeated keys (uris=a&uris=b), not uris[0]=a
            $query = implode('&', array_map(fn (string $uri) => 'uris='.rawurlencode($uri), $chunk));
            $response = Http::acceptJson()->get($this->appview($context).'app.bsky.feed.getPosts?'.$query);

            if (! $response->successful()) {
                foreach ($chunk as $uri) {
                    $result->addError($this->httpError($response, MetricScope::Post, $uri));
                }

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

            array_push($missing, ...array_diff($chunk, $seen));
        }

        $this->explainMissing($missing, $context, $result);

        return $result;
    }

    /**
     * Report the posts getPosts left out. One getProfile per distinct author:
     * an active author means the post itself was deleted (NotFound); otherwise
     * the profile error is reported for the post, so a deactivated or taken
     * down account is Unknown rather than NotFound (see classifyError).
     *
     * @param  list<string>  $uris
     */
    private function explainMissing(array $uris, MetricsContext $context, DriverResult $result): void
    {
        if ($uris === []) {
            return;
        }

        $authors = array_values(array_unique(array_map(fn (string $uri) => $this->author($uri), $uris)));
        $base = $this->appview($context);

        $profiles = Http::pool(function (Pool $pool) use ($authors, $base) {
            foreach ($authors as $did) {
                $pool->as($did)->acceptJson()->get($base.'app.bsky.actor.getProfile', ['actor' => $did]);
            }
        });

        foreach ($uris as $uri) {
            $profile = $profiles[$this->author($uri)] ?? null;

            $result->addError($profile instanceof Response && $profile->successful()
                ? new MetricsError(
                    'bluesky', MetricScope::Post, $uri, ErrorReason::NotFound,
                    'bluesky: the post was deleted (its author is still active).',
                )
                : $this->slotError($profile, MetricScope::Post, $uri, 'getProfile'));
        }
    }

    /** The DID in at://{did}/app.bsky.feed.post/{rkey}. */
    private function author(string $uri): string
    {
        return explode('/', substr($uri, strlen('at://')), 2)[0];
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $actor = $context->meta['did'] ?? $context->accountId;

        if (! $actor) {
            return $result->addError(new MetricsError(
                'bluesky', MetricScope::Account, null, ErrorReason::Configuration,
                'Missing Bluesky account: pass its DID (or handle) as accountId, or meta[did].',
            ));
        }

        $response = Http::acceptJson()->get($this->appview($context).'app.bsky.actor.getProfile', ['actor' => (string) $actor]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $profile = (array) $response->json();

        return $result->setAccount(new AccountMetrics(
            platform: 'bluesky',
            // The identifier asked for, so accountFor() finds it by handle too;
            // the DID is in raw.
            accountId: (string) $actor,
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
     *
     * A deactivated account (by its owner) or a taken down one (by moderation)
     * can both come back, so they are not NotFound, which consumers treat as
     * gone for good. No reason means "unavailable for now", so they are
     * Unknown: logged and not retried, but not dropped.
     */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        $error = (string) ($body['error'] ?? '');
        $message = strtolower((string) ($body['message'] ?? ''));

        return match (true) {
            in_array($error, ['AccountDeactivated', 'AccountTakedown'], true) => ErrorReason::Unknown,
            $error === 'NotFound', str_contains($message, 'not found') => ErrorReason::NotFound,
            default => parent::classifyError($status, $body),
        };
    }

    /** config['appview'] points at another AppView (a self-hosted one). */
    private function appview(MetricsContext $context): string
    {
        return rtrim((string) ($context->config['appview'] ?? self::APPVIEW), '/').'/';
    }
}
