<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Concerns\ClassifiesGraphErrors;
use Pr4w\SocialMetrics\Concerns\FetchesGraphInsights;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * Facebook Pages via the Graph API. Two content types, two endpoints:
 *
 *   - Feed posts: nativeId is the composite "{pageId}_{postId}" (it MUST contain
 *     the underscore). Hits /{id}/insights for reactions + unique impressions.
 *   - Reels: nativeId is the bare video id (no underscore). Hits
 *     /{id}/video_insights for the reel metric set.
 *
 * Routing is by id shape (underscore present = post), which matches how Facebook
 * forms these ids. A caller can force a type with meta['facebook_content'] =
 * 'post' | 'reel'; forcing 'post' on an id with no underscore is reported as a
 * malformed id rather than guessed.
 *
 * Facebook does not return comments or shares as discrete counts on either
 * endpoint (reels group them under post_video_social_actions, kept in raw), so
 * those fields are null. Needs a Page access token.
 */
class FacebookDriver extends AbstractDriver
{
    use ClassifiesGraphErrors;
    use FetchesGraphInsights;

    private const POST_METRICS = 'post_impressions_unique,post_reactions_by_type_total';

    private const REEL_METRICS = 'blue_reels_play_count,fb_reels_total_plays,fb_reels_replay_count,post_video_avg_time_watched,post_video_view_time,post_video_followers,post_video_likes_by_reaction_type,post_video_social_actions';

    public function platform(): string
    {
        return 'facebook';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;

        // Resolve content type per id (a forced post without an underscore is
        // malformed), building the batch requests and remembering each type.
        $requests = [];
        $types = [];

        foreach ($nativeIds as $id) {
            $type = $this->contentType($id, $context);

            if ($type === 'post' && ! str_contains($id, '_')) {
                $result->addError(new MetricsError(
                    'facebook', MetricScope::Post, $id, ErrorReason::Configuration,
                    'Malformed Facebook post id: expected the composite "{pageId}_{postId}".',
                ));

                continue;
            }

            $types[$id] = $type;
            $requests[$id] = $type === 'reel'
                ? "{$id}/video_insights?metric=" . self::REEL_METRICS
                : "{$id}/insights?metric=" . self::POST_METRICS;
        }

        $this->graphBatch($this->graphVersion($context), $context->accessToken, $requests, fn (string $id, array $insights) => $types[$id] === 'reel'
            ? $this->mapReel($id, $insights)
            : $this->mapPost($id, $insights), $result);

        return $result;
    }

    private function contentType(string $id, MetricsContext $context): string
    {
        $forced = $context->meta['facebook_content'] ?? null;

        if ($forced === 'reel' || $forced === 'post') {
            return $forced;
        }

        return str_contains($id, '_') ? 'post' : 'reel';
    }

    /** Feed post: reactions summed into likes, unique impressions as reach. */
    private function mapPost(string $id, array $insights): PostMetrics
    {
        return new PostMetrics(
            platform: 'facebook',
            nativeId: $id,
            likes: $this->sumReactions($insights['post_reactions_by_type_total'] ?? null),
            reach: $this->toInt($insights['post_impressions_unique'] ?? null),
            raw: $insights,
            fetchedAt: now()->toImmutable(),
        );
    }

    /** Reel: plays as views, unique impressions as reach, reaction map summed into likes. */
    private function mapReel(string $id, array $insights): PostMetrics
    {
        return new PostMetrics(
            platform: 'facebook',
            nativeId: $id,
            views: $this->toInt($insights['blue_reels_play_count'] ?? null),
            likes: $this->sumReactions($insights['post_video_likes_by_reaction_type'] ?? null),
            reach: $this->toInt($insights['post_impressions_unique'] ?? null),
            // comments + shares are grouped under post_video_social_actions; raw carries
            // it plus plays_total, replays, watch times and follows.
            raw: $insights,
            fetchedAt: now()->toImmutable(),
        );
    }

    /** Reaction breakdowns come as a {like, love, ...} map; sum it. Absent => null. */
    private function sumReactions(mixed $value): ?int
    {
        if (is_array($value)) {
            return (int) array_sum($value);
        }

        return $this->toInt($value);
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $this->graphVersion($context);
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
