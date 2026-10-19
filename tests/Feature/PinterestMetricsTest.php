<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\AccountRef;
use Pr4w\SocialMetrics\Support\PostRef;

const PIN_LIVE = '1145884699017418234';
const PIN_GONE = '1145884699017415458';
const PIN_BOARD = '1145884767630869253';

/** Pinterest's answer to GET /pins/{id}/analytics, keyed by app type. */
function pinAnalytics(array $summary = [], array $lifetime = []): array
{
    return ['all' => [
        'daily_metrics' => [['date' => '2026-10-18', 'data_status' => 'READY', 'metrics' => ['IMPRESSION' => 40]]],
        'summary_metrics' => $summary + ['IMPRESSION' => 240, 'SAVE' => 20, 'PIN_CLICK' => 37, 'OUTBOUND_CLICK' => 12],
        'lifetime_metrics' => $lifetime + ['TOTAL_REACTIONS' => 9, 'TOTAL_COMMENTS' => 3],
    ]];
}

it('reads a Pin\'s impressions and saves over 90 days, its reactions and comments over its life', function () {
    $this->travelTo('2026-10-19 12:00:00');
    Http::fake(['api.pinterest.com/v5/pins/'.PIN_LIVE.'/analytics*' => Http::response(pinAnalytics())]);

    $post = SocialMetrics::fetchPosts([PostRef::make('pinterest', PIN_LIVE, accessToken: 'pina-1')])->postFor('pinterest', PIN_LIVE);

    expect($post->views)->toBe(240)
        ->and($post->saves)->toBe(20)
        ->and($post->likes)->toBe(9)
        ->and($post->comments)->toBe(3)
        ->and($post->shares)->toBeNull()
        ->and($post->reach)->toBeNull()
        ->and($post->raw['summary_metrics']['OUTBOUND_CLICK'])->toBe(12)
        ->and($post->raw['start_date'])->toBe('2026-07-22');

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://api.pinterest.com/v5/pins/'.PIN_LIVE.'/analytics?')
            && $request->hasHeader('Authorization', 'Bearer pina-1')
            && $query === [
                'start_date' => '2026-07-22',
                'end_date' => '2026-10-19',
                'metric_types' => 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK,TOTAL_REACTIONS,TOTAL_COMMENTS',
            ];
    });
});

it('keeps unknown counts null, not zero', function () {
    Http::fake(['api.pinterest.com/*' => Http::response(['all' => ['summary_metrics' => ['IMPRESSION' => 0], 'lifetime_metrics' => []]])]);

    $post = SocialMetrics::fetchPosts([PostRef::make('pinterest', PIN_LIVE, accessToken: 'pina-1')])->postFor('pinterest', PIN_LIVE);

    expect($post->views)->toBe(0)
        ->and($post->saves)->toBeNull()
        ->and($post->likes)->toBeNull()
        ->and($post->comments)->toBeNull();
});

it('reports a deleted Pin as not found and an analytics refusal as permission', function (int $status, array $body, ErrorReason $reason) {
    Http::fake(['api.pinterest.com/*' => Http::response($body, $status)]);

    $error = SocialMetrics::fetchPosts([PostRef::make('pinterest', PIN_GONE, accessToken: 'pina-1')])->errors->sole();

    expect($error->reason)->toBe($reason)
        ->and($error->nativeId)->toBe(PIN_GONE)
        ->and($error->message)->toBe('pinterest: '.$body['message']);
})->with([
    'deleted' => [404, ['code' => 50, 'message' => 'Pin not found.'], ErrorReason::NotFound],
    'not allowed' => [403, ['code' => 29, 'message' => 'You are not permitted to access that resource.'], ErrorReason::Permission],
    'refused token' => [401, ['code' => 2, 'message' => 'Authentication failed.'], ErrorReason::NeedsReconnect],
    'rate limited' => [429, ['code' => 8, 'message' => 'You have exceeded your rate limit. Try again later.'], ErrorReason::RateLimited],
]);

it('stops asking after a refused token, and fails the remaining Pins the same way', function () {
    Http::fake(['api.pinterest.com/*' => Http::response(['code' => 2, 'message' => 'Authentication failed.'], 401)]);

    $result = SocialMetrics::fetchPosts([
        PostRef::make('pinterest', PIN_LIVE, accessToken: 'pina-1'),
        PostRef::make('pinterest', PIN_GONE, accessToken: 'pina-1'),
    ]);

    expect($result->errors)->toHaveCount(2)
        ->and($result->errors->every(fn ($error) => $error->reason === ErrorReason::NeedsReconnect))->toBeTrue();

    Http::assertSentCount(1);
});

it('reads a board\'s followers and Pins as the account', function () {
    Http::fake(['api.pinterest.com/v5/boards/'.PIN_BOARD => Http::response([
        'id' => PIN_BOARD, 'name' => 'NotionScheduler test', 'privacy' => 'PUBLIC', 'follower_count' => 14, 'pin_count' => 31, 'collaborator_count' => 0, 'description' => 'Not kept',
    ])]);

    $account = SocialMetrics::fetchAccounts([AccountRef::make('pinterest', PIN_BOARD, 'pina-1')])->accountFor('pinterest', PIN_BOARD);

    expect($account->followers)->toBe(14)
        ->and($account->posts)->toBe(31)
        ->and($account->following)->toBeNull()
        ->and($account->raw)->not->toHaveKey('description');
});

it('reads the board given in meta when accountId is the caller\'s own label', function () {
    Http::fake(['api.pinterest.com/v5/boards/'.PIN_BOARD => Http::response(['id' => PIN_BOARD, 'follower_count' => 2, 'pin_count' => 5])]);

    $result = SocialMetrics::fetchAccounts([AccountRef::make('pinterest', 9, 'pina-1', meta: ['board_id' => PIN_BOARD])]);

    expect($result->accounts->sole()->followers)->toBe(2);
});

it('calls the host in meta, then the configured one', function () {
    config(['social-metrics.drivers.pinterest.api_url' => 'https://api-sandbox.pinterest.com/v5']);
    Http::fake(['*' => Http::response(['id' => PIN_BOARD, 'follower_count' => 0, 'pin_count' => 3])]);

    SocialMetrics::fetchAccounts([AccountRef::make('pinterest', PIN_BOARD, 'pina-1')]);
    SocialMetrics::fetchAccounts([AccountRef::make('pinterest', PIN_BOARD, 'pina-1', meta: ['api_url' => 'https://pinterest.test/v5/'])]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api-sandbox.pinterest.com/v5/boards/'.PIN_BOARD);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://pinterest.test/v5/boards/'.PIN_BOARD);
});
