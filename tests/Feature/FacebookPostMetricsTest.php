<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\MetricsResult;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\PostRef;

const FB_POST_ID = '1000_2000';
const FB_REEL_ID = '1000_3000';
const FB_REEL_VIDEO_ID = '3001';
const FB_VIDEO_ID = '4000';

/** Graph insight rows for the given name => value pairs. */
function fbInsights(array $values): array
{
    return ['data' => array_map(
        fn (string $name, mixed $value) => ['name' => $name, 'period' => 'lifetime', 'values' => [['value' => $value]]],
        array_keys($values),
        $values,
    )];
}

function fbAttachment(string $mediaType, ?string $targetId = null): array
{
    return ['attachments' => ['data' => [array_filter([
        'media_type' => $mediaType,
        'target' => $targetId ? ['id' => $targetId] : null,
    ])]]];
}

function fbError(int $code, string $message, ?int $subcode = null): array
{
    return ['error' => array_filter([
        'message' => $message,
        'type' => 'OAuthException',
        'code' => $code,
        'error_subcode' => $subcode,
    ])];
}

/**
 * Fake the Graph Batch endpoint. $route receives each sub-request's decoded
 * relative_url and returns its [code, body].
 */
function fakeGraph(callable $route): void
{
    Http::fake(['graph.facebook.com/*' => function (Request $request) use ($route) {
        return Http::response(array_map(function (array $sub) use ($route) {
            [$code, $body] = $route(rawurldecode($sub['relative_url']));

            return ['code' => $code, 'body' => json_encode($body)];
        }, json_decode($request->data()['batch'], true)));
    }]);
}

/** Every relative_url sent through the Graph Batch endpoint. */
function graphRequests(): array
{
    return Http::recorded()
        ->flatMap(fn (array $pair) => json_decode($pair[0]->data()['batch'], true))
        ->map(fn (array $sub) => rawurldecode($sub['relative_url']))
        ->all();
}

function fetchFacebook(array $ids, array $meta = []): MetricsResult
{
    return SocialMetrics::fetchPosts(array_map(
        fn (string $id) => PostRef::make('facebook', $id, accountId: 1, accessToken: 'token', meta: $meta),
        $ids,
    ));
}

$feedPost = fbInsights([
    'post_media_view' => 1200,
    'post_total_media_view_unique' => 800,
    'post_reactions_by_type_total' => ['like' => 40, 'love' => 5],
]);

$reel = fbInsights([
    'blue_reels_play_count' => 5000,
    'fb_reels_total_plays' => 5600,
    'post_video_likes_by_reaction_type' => ['REACTION_LIKE' => 90, 'REACTION_LOVE' => 10],
]);

it('reads a feed post with the current metric names', function () use ($feedPost) {
    fakeGraph(fn (string $url) => match (true) {
        str_starts_with($url, FB_POST_ID . '?fields=') => [200, fbAttachment('photo', '999')],
        str_starts_with($url, FB_POST_ID . '/insights') => [200, $feedPost],
    });

    $result = fetchFacebook([FB_POST_ID]);
    $post = $result->postFor('facebook', FB_POST_ID);

    expect($result->errors)->toBeEmpty()
        ->and($post->views)->toBe(1200)
        ->and($post->reach)->toBe(800)
        ->and($post->likes)->toBe(45)
        ->and(implode(' ', graphRequests()))->not->toContain('post_impressions')
        ->and(graphRequests())->toContain(FB_POST_ID . '/insights?metric=post_media_view,post_total_media_view_unique,post_reactions_by_type_total');
});

it('reads a reel stored as {page}_{post} from video_insights on its video id', function () use ($reel) {
    fakeGraph(fn (string $url) => match (true) {
        str_starts_with($url, FB_REEL_ID . '?fields=') => [200, fbAttachment('video', FB_REEL_VIDEO_ID)],
        str_starts_with($url, FB_REEL_VIDEO_ID . '/video_insights') => [200, $reel],
        default => [400, fbError(100, 'Unexpected request')],
    });

    $result = fetchFacebook([FB_REEL_ID]);
    $post = $result->postFor('facebook', FB_REEL_ID);

    expect($result->errors)->toBeEmpty()
        ->and($post->views)->toBe(5000)
        ->and($post->likes)->toBe(100)
        ->and($post->reach)->toBeNull()
        ->and($post->raw['video_id'])->toBe(FB_REEL_VIDEO_ID)
        ->and(implode(' ', graphRequests()))->not->toContain(FB_REEL_ID . '/insights');
});

it('reads a bare video id from video_insights without a lookup', function () use ($reel) {
    fakeGraph(fn (string $url) => str_starts_with($url, FB_VIDEO_ID . '/video_insights')
        ? [200, $reel]
        : [400, fbError(100, 'Unexpected request')]);

    $result = fetchFacebook([FB_VIDEO_ID]);

    expect($result->errors)->toBeEmpty()
        ->and($result->postFor('facebook', FB_VIDEO_ID)->views)->toBe(5000)
        ->and(graphRequests())->toHaveCount(1);
});

it('skips the lookup when the type is forced', function () use ($feedPost, $reel) {
    fakeGraph(fn (string $url) => match (true) {
        str_starts_with($url, FB_POST_ID . '/insights') => [200, $feedPost],
        str_starts_with($url, FB_REEL_VIDEO_ID . '/video_insights') => [200, $reel],
        str_starts_with($url, FB_REEL_ID . '?fields=') => [200, fbAttachment('photo', FB_REEL_VIDEO_ID)],
        default => [400, fbError(100, 'Unexpected request')],
    });

    $post = fetchFacebook([FB_POST_ID], ['facebook_content' => 'post']);
    $reelResult = fetchFacebook([FB_REEL_ID], ['facebook_content' => 'reel']);

    expect($post->errors)->toBeEmpty()
        ->and($post->postFor('facebook', FB_POST_ID)->views)->toBe(1200)
        ->and($reelResult->errors)->toBeEmpty()
        ->and($reelResult->postFor('facebook', FB_REEL_ID)->views)->toBe(5000);
});

it('reports a retired metric as Configuration, not NotFound', function () {
    fakeGraph(fn (string $url) => str_contains($url, '/insights')
        ? [400, fbError(100, 'The value must be a valid insights metric')]
        : [200, fbAttachment('photo')]);

    $error = fetchFacebook([FB_POST_ID])->errors->sole();

    expect($error->reason)->toBe(ErrorReason::Configuration)
        ->and($error->nativeId)->toBe(FB_POST_ID)
        ->and($error->retryable())->toBeFalse();
});

it('reports a deleted post as NotFound', function (array $graphError) {
    fakeGraph(fn (string $url) => [400, $graphError]);

    $error = fetchFacebook([FB_POST_ID])->errors->sole();

    expect($error->reason)->toBe(ErrorReason::NotFound)
        ->and($error->nativeId)->toBe(FB_POST_ID);
})->with([
    'subcode 33' => [fbError(100, "Unsupported get request. Object with ID '1000_2000' does not exist", 33)],
    'message only' => [fbError(100, "Object with ID '1000_2000' does not exist")],
]);

it('still rejects a forced post on a bare id', function () {
    fakeGraph(fn (string $url) => [400, fbError(100, 'Unexpected request')]);

    $error = fetchFacebook([FB_VIDEO_ID], ['facebook_content' => 'post'])->errors->sole();

    expect($error->reason)->toBe(ErrorReason::Configuration)
        ->and($error->message)->toContain('Malformed')
        ->and(graphRequests())->toBeEmpty();
});
