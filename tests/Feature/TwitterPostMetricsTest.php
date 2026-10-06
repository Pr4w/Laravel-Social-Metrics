<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\MetricsResult;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\MetricsContext;
use Pr4w\SocialMetrics\Support\PostRef;

const X_LIVE_ID = '1840000000000000001';
const X_DELETED_ID = '1840000000000000002';

function xMetrics(int $seed = 1): array
{
    return [
        'retweet_count' => 3 * $seed,
        'reply_count' => 4 * $seed,
        'like_count' => 50 * $seed,
        'quote_count' => 2 * $seed,
        'bookmark_count' => 6 * $seed,
        'impression_count' => 1200 * $seed,
    ];
}

function xNotFound(string $id): array
{
    return [
        'value' => $id,
        'detail' => "Could not find tweet with ids: [{$id}].",
        'title' => 'Not Found Error',
        'resource_type' => 'tweet',
        'parameter' => 'ids',
        'resource_id' => $id,
        'type' => 'https://api.twitter.com/2/problems/resource-not-found',
    ];
}

/**
 * Fake GET /2/tweets. Ids in $deleted come back in `errors`, every other
 * requested id in `data`. $status, when set, fails the whole call instead.
 */
function fakeX(array $deleted = [], ?int $status = null, array $problem = []): void
{
    Http::fake(['api.x.com/2/tweets*' => function (Request $request) use ($deleted, $status, $problem) {
        if ($status !== null) {
            return Http::response($problem, $status);
        }

        $ids = explode(',', $request->data()['ids']);
        $found = array_values(array_diff($ids, $deleted));
        $missing = array_values(array_intersect($ids, $deleted));

        return Http::response(array_filter([
            'data' => array_map(fn (string $id) => [
                'id' => $id,
                'text' => 'hello',
                'edit_history_tweet_ids' => [$id],
                'public_metrics' => xMetrics(),
            ], $found),
            'errors' => array_map('xNotFound', $missing),
        ]));
    }]);
}

/** The ids sent in each recorded /2/tweets call. */
function xRequestedIds(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => explode(',', $pair[0]->data()['ids']))
        ->all();
}

function fetchX(array $ids): MetricsResult
{
    return SocialMetrics::fetchPosts(array_map(
        fn (string $id) => PostRef::make('twitter', $id, accountId: 1, accessToken: 'x-token'),
        $ids,
    ));
}

/** @return list<string> */
function xIds(int $count): array
{
    return array_map(fn (int $n) => (string) (1840000000000100000 + $n), range(1, $count));
}

it('maps public_metrics and reports the deleted id of a batch of two as NotFound', function () {
    fakeX(deleted: [X_DELETED_ID]);

    $result = fetchX([X_LIVE_ID, X_DELETED_ID]);
    $post = $result->postFor('twitter', X_LIVE_ID);
    $error = $result->errors->sole();

    expect($result->posts)->toHaveCount(1)
        ->and($post->views)->toBe(1200)
        ->and($post->likes)->toBe(50)
        ->and($post->comments)->toBe(4)
        ->and($post->shares)->toBe(5)          // 3 retweets + 2 quotes
        ->and($post->saves)->toBe(6)
        ->and($post->reach)->toBeNull()
        ->and($post->raw)->toBe(xMetrics())
        ->and($error->reason)->toBe(ErrorReason::NotFound)
        ->and($error->nativeId)->toBe(X_DELETED_ID)
        ->and($error->retryable())->toBeFalse()
        ->and(xRequestedIds())->toBe([[X_LIVE_ID, X_DELETED_ID]]);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer x-token')
        && $r->data()['tweet.fields'] === 'public_metrics');
});

it('requests each id once, even when it is passed twice', function () {
    fakeX();

    $result = SocialMetrics::driver('twitter')->fetchPostMetrics(
        [X_LIVE_ID, X_LIVE_ID, X_DELETED_ID],
        new MetricsContext('twitter', 'x-token'),
    );

    expect($result->posts)->toHaveCount(2)
        ->and(xRequestedIds())->toBe([[X_LIVE_ID, X_DELETED_ID]]);
});

it('batches ids by 100', function () {
    fakeX();

    $result = fetchX(xIds(150));

    expect($result->posts)->toHaveCount(150)
        ->and($result->errors)->toBeEmpty()
        ->and(array_map('count', xRequestedIds()))->toBe([100, 50]);
});

it('classifies whole-call failures for every requested id', function (int $status, ErrorReason $reason, bool $retryable) {
    fakeX(status: $status, problem: ['title' => 'Problem', 'detail' => "HTTP {$status} detail", 'status' => $status]);

    $result = fetchX([X_LIVE_ID, X_DELETED_ID]);

    expect($result->posts)->toBeEmpty()
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errors->pluck('nativeId')->all())->toBe([X_LIVE_ID, X_DELETED_ID])
        ->and($result->errors->every(fn ($e) => $e->reason === $reason && $e->retryable() === $retryable))->toBeTrue()
        ->and($result->errors->first()->message)->toBe("twitter: HTTP {$status} detail");
})->with([
    'expired token' => [401, ErrorReason::NeedsReconnect, false],
    'rate limited' => [429, ErrorReason::RateLimited, true],
    'out of credits' => [402, ErrorReason::Configuration, false],
]);

it('stops sending batches once the account is out of credits', function () {
    fakeX(status: 402, problem: ['title' => 'CreditsDepleted', 'detail' => 'Your account has no credits.']);

    $result = fetchX(xIds(250));

    expect(xRequestedIds())->toHaveCount(1)
        ->and($result->errors)->toHaveCount(250)
        ->and($result->errors->every(fn ($e) => $e->reason === ErrorReason::Configuration))->toBeTrue();
});
