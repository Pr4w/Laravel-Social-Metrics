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
 * Facebook Pages via the Graph API. Two kinds of content, two endpoints:
 *
 *   - Feed posts: /{postId}/insights on the composite "{pageId}_{postId}".
 *     views = post_media_view, reach = post_total_media_view_unique, likes =
 *     post_reactions_by_type_total summed (post_impressions* were retired on
 *     June 15, 2026).
 *   - Reels and videos: /{videoId}/video_insights, which only takes the video
 *     id. views = blue_reels_play_count, likes = post_video_likes_by_reaction_type
 *     summed. Reach is not requested (post_impressions_unique is retired), so it
 *     stays null.
 *
 * A bare id (no underscore) is a video id and goes straight to video_insights.
 * A composite id may be a feed post or a reel (reels must be stored composite:
 * Graph refuses the bare reel post id with "#12 singular statuses API is
 * deprecated"), so its first attachment is read to tell them apart: a video
 * attachment sends its target id to video_insights. meta['facebook_content'] =
 * 'post' | 'reel' forces the type; forcing 'post' on a bare id is reported as a
 * malformed id rather than guessed.
 *
 * PostMetrics::nativeId is always the id the caller passed; the video id is
 * kept in raw['video_id']. Facebook does not return comments or shares as
 * discrete counts on either endpoint (reels group them under
 * post_video_social_actions, kept in raw), so those fields are null. Needs a
 * Page access token.
 */
class FacebookDriver extends AbstractDriver
{
    use ClassifiesGraphErrors;
    use FetchesGraphInsights;

    private const POST_METRICS = 'post_media_view,post_total_media_view_unique,post_reactions_by_type_total';

    private const REEL_METRICS = 'blue_reels_play_count,fb_reels_total_plays,fb_reels_replay_count,post_video_avg_time_watched,post_video_view_time,post_video_followers,post_video_likes_by_reaction_type,post_video_social_actions';

    /** Fields that tell a feed post from a reel or video post. */
    private const ATTACHMENT_FIELDS = 'attachments{media_type,type,target{id}}';

    public function platform(): string
    {
        return 'facebook';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $version = $this->graphVersion($context);
        $targets = $this->resolveTargets($nativeIds, $context, $version, $result);

        $requests = [];

        foreach ($targets as $id => $videoId) {
            $requests[$id] = $videoId !== null
                ? "{$videoId}/video_insights?metric=" . self::REEL_METRICS
                : "{$id}/insights?metric=" . self::POST_METRICS;
        }

        $this->graphBatch($version, $context->accessToken, $requests, fn (string $id, array $insights) => $targets[$id] !== null
            ? $this->mapReel($id, $targets[$id], $insights)
            : $this->mapPost($id, $insights), $result);

        return $result;
    }

    /**
     * Decide where each id's insights live. Returns nativeId => video id for
     * reels and videos (read from video_insights), or null for feed posts (read
     * from insights). Ids that cannot be resolved are recorded as errors and
     * left out.
     *
     * @return array<string, string|null>
     */
    private function resolveTargets(array $nativeIds, MetricsContext $context, string $version, DriverResult $result): array
    {
        $forced = $context->meta['facebook_content'] ?? null;
        $targets = [];
        $lookups = [];

        foreach ($nativeIds as $id) {
            $composite = str_contains($id, '_');

            if ($forced === 'post' && ! $composite) {
                $result->addError(new MetricsError(
                    'facebook', MetricScope::Post, $id, ErrorReason::Configuration,
                    'Malformed Facebook post id: expected the composite "{pageId}_{postId}".',
                ));
            } elseif (! $composite) {
                $targets[$id] = $id;
            } elseif ($forced === 'post') {
                $targets[$id] = null;
            } else {
                $lookups[$id] = "{$id}?fields=" . rawurlencode(self::ATTACHMENT_FIELDS);
            }
        }

        $this->graphBatchEach($version, $context->accessToken, $lookups, function (string $id, array $body) use (&$targets, $forced, $result) {
            $attachment = $body['attachments']['data'][0] ?? [];
            $videoId = $attachment['target']['id'] ?? null;

            if ($videoId !== null && ($forced === 'reel' || $this->isVideo($attachment))) {
                $targets[$id] = (string) $videoId;
            } elseif ($forced === 'reel') {
                $result->addError(new MetricsError(
                    'facebook', MetricScope::Post, $id, ErrorReason::Configuration,
                    'Forced as a reel, but the post has no video attachment to read video_insights from.',
                ));
            } else {
                $targets[$id] = null;
            }
        }, $result);

        return $targets;
    }

    /**
     * Whether an attachment is a reel or video. media_type is "video" for both;
     * type is checked too in case a reel reports a reel-specific type. Verify
     * against a live reel if reels start landing on /insights.
     */
    private function isVideo(array $attachment): bool
    {
        return strtolower((string) ($attachment['media_type'] ?? '')) === 'video'
            || str_contains(strtolower((string) ($attachment['type'] ?? '')), 'reel');
    }

    /** Feed post: media views as views, unique media viewers as reach, reactions summed into likes. */
    private function mapPost(string $id, array $insights): PostMetrics
    {
        return new PostMetrics(
            platform: 'facebook',
            nativeId: $id,
            views: $this->toInt($insights['post_media_view'] ?? null),
            likes: $this->sumReactions($insights['post_reactions_by_type_total'] ?? null),
            reach: $this->toInt($insights['post_total_media_view_unique'] ?? null),
            raw: $insights,
            fetchedAt: now()->toImmutable(),
        );
    }

    /** Reel or video: plays as views, reaction map summed into likes. */
    private function mapReel(string $id, string $videoId, array $insights): PostMetrics
    {
        return new PostMetrics(
            platform: 'facebook',
            nativeId: $id,
            views: $this->toInt($insights['blue_reels_play_count'] ?? null),
            likes: $this->sumReactions($insights['post_video_likes_by_reaction_type'] ?? null),
            // comments + shares are grouped under post_video_social_actions; raw carries
            // it plus plays_total, replays, watch times and follows.
            raw: $insights + ['video_id' => $videoId],
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
