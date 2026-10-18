<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\AccountRef;
use Pr4w\SocialMetrics\Support\MetricsContext;
use Pr4w\SocialMetrics\Support\PostRef;

const BSKY_DID = 'did:plc:abc';
const BSKY_LIVE = 'at://did:plc:abc/app.bsky.feed.post/3live';
const BSKY_GONE = 'at://did:plc:abc/app.bsky.feed.post/3gone';
const BSKY_PAUSED = 'at://did:plc:paused/app.bsky.feed.post/3paused';

function bskyProfile(): array
{
    return [200, ['did' => BSKY_DID, 'handle' => 'alice.bsky.social', 'followersCount' => 1500, 'followsCount' => 300, 'postsCount' => 42]];
}

function bskyDeactivated(): array
{
    return [400, ['error' => 'AccountDeactivated', 'message' => 'Account is deactivated']];
}

/**
 * Fake the AppView. getPosts returns every requested URI but those in
 * $missing, or fails with $status. getProfile answers from $profiles
 * (actor => [status, body]), an active profile by default.
 */
function fakeBluesky(array $missing = [], ?int $status = null, array $profiles = []): void
{
    Http::fake([
        'public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => function (Request $request) use ($missing, $status) {
            if ($status !== null) {
                return Http::response(['error' => 'InternalServerError', 'message' => 'boom'], $status);
            }

            parse_str(str_replace('uris=', 'uris[]=', parse_url($request->url(), PHP_URL_QUERY)), $query);

            return Http::response(['posts' => array_map(fn (string $uri) => [
                'uri' => $uri, 'cid' => 'bafy', 'likeCount' => 12, 'replyCount' => 3, 'repostCount' => 4, 'quoteCount' => 1, 'bookmarkCount' => 2,
            ], array_values(array_diff($query['uris'], $missing)))]);
        },
        'public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => function (Request $request) use ($profiles) {
            $actor = $request->data()['actor'];
            [$code, $body] = $profiles[$actor] ?? bskyProfile();

            return Http::response($body, $code);
        },
    ]);
}

function bskyProfileCalls(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'app.bsky.actor.getProfile'))
        ->map(fn (array $pair) => $pair[0]->data()['actor'])
        ->values()
        ->all();
}

it('reads post counts from the public AppView, without a token, and reports a deleted post', function () {
    fakeBluesky(missing: [BSKY_GONE]);

    $result = SocialMetrics::fetchPosts([PostRef::make('bluesky', BSKY_LIVE), PostRef::make('bluesky', BSKY_GONE)]);
    $post = $result->postFor('bluesky', BSKY_LIVE);

    expect($post->likes)->toBe(12)
        ->and($post->comments)->toBe(3)
        ->and($post->shares)->toBe(4)
        ->and($post->saves)->toBe(2)
        ->and($post->views)->toBeNull()
        ->and($post->raw['quoteCount'])->toBe(1)
        ->and($result->errors->sole()->reason)->toBe(ErrorReason::NotFound)
        ->and($result->errors->sole()->nativeId)->toBe(BSKY_GONE)
        ->and(bskyProfileCalls())->toBe([BSKY_DID]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uris='.rawurlencode(BSKY_LIVE).'&uris='.rawurlencode(BSKY_GONE))
        && ! $r->hasHeader('Authorization'));
});

it('does not report the posts of a deactivated account as NotFound', function () {
    fakeBluesky(missing: [BSKY_PAUSED], profiles: ['did:plc:paused' => bskyDeactivated()]);

    $result = SocialMetrics::fetchPosts([PostRef::make('bluesky', BSKY_LIVE), PostRef::make('bluesky', BSKY_PAUSED)]);
    $error = $result->errors->sole();

    expect($result->postFor('bluesky', BSKY_LIVE)->likes)->toBe(12)
        ->and($error->nativeId)->toBe(BSKY_PAUSED)
        ->and($error->reason)->toBe(ErrorReason::Unknown)
        ->and($error->message)->toContain('deactivated');
});

it('checks each missing author once, and only for missing posts', function () {
    $gone = array_map(fn (int $n) => "at://did:plc:abc/app.bsky.feed.post/3gone{$n}", range(1, 3));
    fakeBluesky(missing: $gone);

    $result = SocialMetrics::fetchPosts(array_map(fn (string $uri) => PostRef::make('bluesky', $uri), [BSKY_LIVE, ...$gone]));

    expect($result->errors)->toHaveCount(3)
        ->and($result->errors->every(fn ($e) => $e->reason === ErrorReason::NotFound))->toBeTrue()
        ->and(bskyProfileCalls())->toBe([BSKY_DID]);
});

it('asks for 25 URIs per call, each once', function () {
    fakeBluesky();
    $uris = array_map(fn (int $n) => "at://did:plc:abc/app.bsky.feed.post/3k{$n}", range(1, 30));

    $result = SocialMetrics::driver('bluesky')->fetchPostMetrics([...$uris, $uris[0]], new MetricsContext('bluesky'));

    expect($result->posts)->toHaveCount(30)
        ->and($result->errors)->toBeEmpty();
    Http::assertSentCount(2);
});

it('rejects a malformed or handle-based URI without failing the rest of the batch', function (string $uri) {
    fakeBluesky();

    $result = SocialMetrics::fetchPosts([PostRef::make('bluesky', BSKY_LIVE), PostRef::make('bluesky', $uri)]);
    $error = $result->errors->sole();

    expect($result->postFor('bluesky', BSKY_LIVE)->likes)->toBe(12)
        ->and($error->nativeId)->toBe($uri)
        ->and($error->reason)->toBe(ErrorReason::Configuration);

    // The call carried the valid URI only.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'app.bsky.feed.getPosts?uris='.rawurlencode(BSKY_LIVE)));
    Http::assertSentCount(1);
})->with([
    'handle authority' => ['at://alice.bsky.social/app.bsky.feed.post/3live'],
    'web URL' => ['https://bsky.app/profile/alice.bsky.social/post/3live'],
    'bare rkey' => ['3live'],
]);

it('reports a failed call as an HTTP error for each of its URIs', function () {
    fakeBluesky(status: 502);

    $result = SocialMetrics::fetchPosts([PostRef::make('bluesky', BSKY_LIVE), PostRef::make('bluesky', BSKY_GONE)]);

    expect($result->posts)->toBeEmpty()
        ->and($result->errors->pluck('nativeId')->all())->toBe([BSKY_LIVE, BSKY_GONE])
        ->and($result->errors->every(fn ($e) => $e->reason === ErrorReason::HttpError && $e->retryable()))->toBeTrue();
});

it('reads followers, following and posts from the profile', function () {
    fakeBluesky();

    $account = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', BSKY_DID)])->accountFor('bluesky', BSKY_DID);

    expect($account->followers)->toBe(1500)
        ->and($account->following)->toBe(300)
        ->and($account->posts)->toBe(42);
});

it('prefers meta[did] over an accountId that is a database key', function () {
    fakeBluesky();

    $result = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', 7, meta: ['did' => BSKY_DID])]);

    expect(bskyProfileCalls())->toBe([BSKY_DID])
        ->and($result->accountFor('bluesky', BSKY_DID)->followers)->toBe(1500);
});

it('finds an account fetched by handle under that handle', function () {
    fakeBluesky();

    $account = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', 'alice.bsky.social')])
        ->accountFor('bluesky', 'alice.bsky.social');

    expect($account->followers)->toBe(1500)
        ->and($account->raw['did'])->toBe(BSKY_DID);
});

it('reads an unknown profile as not found', function () {
    fakeBluesky(profiles: ['did:plc:nobody' => [400, ['error' => 'InvalidRequest', 'message' => 'Profile not found']]]);

    $result = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', 'did:plc:nobody')]);

    expect($result->errors->sole()->reason)->toBe(ErrorReason::NotFound);
});

it('reads a deactivated or taken down account as Unknown, not NotFound', function (string $error) {
    fakeBluesky(profiles: [BSKY_DID => [400, ['error' => $error, 'message' => 'Account is unavailable']]]);

    $error = SocialMetrics::fetchAccounts([AccountRef::make('bluesky', BSKY_DID)])->errors->sole();

    expect($error->reason)->toBe(ErrorReason::Unknown)
        ->and($error->retryable())->toBeFalse();
})->with(['AccountDeactivated', 'AccountTakedown']);
