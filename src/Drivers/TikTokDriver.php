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
 * TikTok Display API. The video/list endpoint cannot be queried by id, so we
 * list the account's videos once and filter client-side. Requested ids not
 * present in the listing surface as not_found errors.
 */
class TikTokDriver extends AbstractDriver
{
    private const FIELDS = 'id,title,view_count,like_count,comment_count,share_count,create_time,share_url';

    public function platform(): string
    {
        return 'tiktok';
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $max = (int) ($context->config['max_videos'] ?? 200);
        $videos = $this->listAllVideos($context->accessToken, $result, $max);

        foreach ($nativeIds as $id) {
            if (isset($videos[$id])) {
                $result->addPost($videos[$id]);

                continue;
            }

            $result->addError(new MetricsError(
                'tiktok', MetricScope::Post, $id, ErrorReason::NotFound,
                "Video not in the account listing (TikTok cannot query by id; raise drivers.tiktok.max_videos if it is older than {$max} posts ago).",
            ));
        }

        return $result;
    }

    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;

        $response = Http::withToken($context->accessToken)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,follower_count,following_count,likes_count,video_count',
            ]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        $user = $response->json('data.user', []);

        return $result->setAccount(new AccountMetrics(
            platform: 'tiktok',
            accountId: (string) ($user['open_id'] ?? $context->accountId ?? ''),
            followers: $this->int($user, 'follower_count'),
            following: $this->int($user, 'following_count'),
            posts: $this->int($user, 'video_count'),
            raw: $user,
            fetchedAt: now()->toImmutable(),
        ));
    }

    /** @return array<string, PostMetrics> */
    private function listAllVideos(string $token, DriverResult $result, int $maxCount): array
    {
        $videos = [];
        $cursor = 0;
        $hasMore = true;

        while ($hasMore && count($videos) < $maxCount) {
            $response = Http::withToken($token)
                ->withQueryParameters(['fields' => self::FIELDS])
                ->post('https://open.tiktokapis.com/v2/video/list/', [
                    'max_count' => 20,
                    'cursor' => $cursor,
                ]);

            if (! $response->successful()) {
                $result->addError($this->httpError($response, MetricScope::Post));

                break;
            }

            $data = $response->json('data', []);

            foreach ($data['videos'] ?? [] as $video) {
                $videos[$video['id']] = new PostMetrics(
                    platform: 'tiktok',
                    nativeId: $video['id'],
                    views: $this->int($video, 'view_count'),
                    likes: $this->int($video, 'like_count'),
                    comments: $this->int($video, 'comment_count'),
                    shares: $this->int($video, 'share_count'),
                    raw: $video,
                    fetchedAt: now()->toImmutable(),
                );
            }

            $hasMore = $data['has_more'] ?? false;
            $cursor = $data['cursor'] ?? 0;
        }

        return $videos;
    }
}
