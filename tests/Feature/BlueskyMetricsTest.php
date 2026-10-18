<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\AccountRef;
use Pr4w\SocialMetrics\Support\PostRef;

const BSKY_LIVE = 'at://did:plc:abc/app.bsky.feed.post/3live';
const BSKY_GONE = 'at://did:plc:abc/app.bsky.feed.post/3gone';

/** Fake getPosts: every requested URI but those in $deleted comes back. */
function fakeBlueskyPosts(array $deleted = [], ?int $status = null): void
{
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => function (Request $request) use ($deleted, $status) {
        if ($status !== null) {
            return Http::response(['error' => 'InternalServerError', 'message' => 'boom'], $status);
        }

        parse_str(str_replace('uris=', 'uris[]=', parse_url($request->url(), PHP_URL_QUERY)), $query);

        return Http::response(['posts' => array_map(fn (string $uri) => [
            'uri' => $uri, 'cid' => 'bafy', 'likeCount' => 12, 'replyCount' => 3, 'repostCount' => 4, 'quoteCount' => 1, 'bookmarkCount' => 2,
        ], array_values(array_diff($query['uris'], $deleted)))]);
    }]);
}

it('reads post counts from the public AppView, without a token, and reports a deleted post', function () {
    fakeBlueskyPosts(deleted: [BSKY_GONE]);

    $result = SocialMetrics::fetchPosts([PostRef::make('bluesky', BSKY_LIVE), PostRef::make('bluesky', BSKY_GONE)]);
    $post = $result->postFor('bluesky', BSKY_LIVE);

    expect($post->likes)->toBe(12)
        ->and($post->comments)->toBe(3)
        ->and($post->shares)->toBe(4)
        ->and($post->saves)->toBe(2)
        ->and($post->views)->toBeNull()
        ->and($post->raw['quoteCount'])->toBe(1)
        ->and($result->errors->sole()->reason)->toBe(ErrorReason::NotFound)
        ->and($result->errors->sole()->nativeId)->toBe(BSKY_GONE);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uris='.rawurlencode(BSKY_LIVE).'&uris='.rawurlencode(BSKY_GONE))
        && ! $r->hasHeader('Authorization'));
});

it('asks for 25 URIs per call, each once', function () {
    fakeBlueskyPosts();
    $uris = array_map(fn (int $n) => "at://did:plc:abc/app.bsky.feed.post/3k{$n}", range(1, 30));

    $result = SocialMetrics::driver('bluesky')->fetchPostMetrics([...$uris, $uris[0]], new \Pr4w\SocialMetrics\Support\MetricsContext('bluesky'));

    expect($result->posts)->toHaveCount(30);
    Http::assertSentCount(2);
});

it('reports a failed call as an HTTP error for its URIs', function () {
    fakeBlueskyPosts(status: 502);

    $result = SocialMetrics::fetchPosts([PostRef::make('bluesky', BSKY_LIVE)]);

    expect($result->posts)->toBeEmpty()
        ->and($result->errors->first()->reason)->toBe(ErrorReason::HttpError);
});

it('reads followers, following and posts from the profile', function () {
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response([
        'did' => 'did:plc:abc', 'handle' => 'alice.bsky.social', 'followersCount' => 1500, 'followsCount' => 300, 'postsCount' => 42,
    ])]);

    $account = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', 'did:plc:abc')])->accountFor('bluesky', 'did:plc:abc');

    expect($account->followers)->toBe(1500)
        ->and($account->following)->toBe(300)
        ->and($account->posts)->toBe(42);
});

it('reads an unknown profile as not found', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['error' => 'InvalidRequest', 'message' => 'Profile not found'], 400)]);

    $result = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', 'did:plc:nobody')]);

    expect($result->errors->sole()->reason)->toBe(ErrorReason::NotFound);
});
