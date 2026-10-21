<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\AccountRef;
use Pr4w\SocialMetrics\Support\PostRef;

const IG_MEDIA = '17900000000000001';
const IG_USER = '17841400000000001';

/**
 * Fake graph.facebook.com for the Facebook-Login Instagram driver: a Graph
 * Batch POST answers each insights sub-request; a GET answers the account.
 */
function fakeInstagramGraph(): void
{
    Http::fake(['graph.facebook.com/*' => function (Request $request) {
        if ($request->method() === 'POST') {
            return Http::response(array_map(fn (array $sub) => [
                'code' => 200,
                'body' => json_encode(['data' => [
                    ['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 700]]],
                    ['name' => 'likes', 'period' => 'lifetime', 'values' => [['value' => 30]]],
                    ['name' => 'saved', 'period' => 'lifetime', 'values' => [['value' => 4]]],
                ]]),
            ], json_decode($request->data()['batch'], true)));
        }

        return Http::response(['followers_count' => 2000, 'follows_count' => 50, 'media_count' => 120]);
    }]);
}

it('still reads post insights through one Graph Batch on graph.facebook.com', function () {
    fakeInstagramGraph();

    $result = SocialMetrics::fetchPosts([PostRef::make('instagram', IG_MEDIA, accessToken: 'EAA-token')]);
    $post = $result->postFor('instagram', IG_MEDIA);

    expect($result->errors)->toBeEmpty()
        ->and($post->platform)->toBe('instagram')
        ->and($post->views)->toBe(700)
        ->and($post->likes)->toBe(30)
        ->and($post->saves)->toBe(4)
        ->and($post->reach)->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://graph.facebook.com/v25.0/'
        && json_decode($r->data()['batch'], true) === [[
            'method' => 'GET',
            'relative_url' => IG_MEDIA.'/insights?metric=views,reach,likes,comments,shares,saved,total_interactions',
        ]]);
});

it('still reads account metrics on graph.facebook.com', function () {
    fakeInstagramGraph();

    $account = SocialMetrics::fetchAccounts([AccountRef::make('instagram', IG_USER, 'EAA-token')])->accountFor('instagram', IG_USER);

    expect($account->platform)->toBe('instagram')
        ->and($account->followers)->toBe(2000)
        ->and($account->following)->toBe(50)
        ->and($account->posts)->toBe(120);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v25.0/'.IG_USER.'?'));
});

it('still reports a missing account id as Configuration for instagram', function () {
    Http::fake();

    $error = SocialMetrics::fetchAccounts([AccountRef::make('instagram', accessToken: 'EAA-token')])->errors->sole();

    expect($error->reason)->toBe(ErrorReason::Configuration)
        ->and($error->platform)->toBe('instagram')
        ->and($error->message)->toBe('Missing IG business user id (pass it as accountId or meta ig_user_id).');
});
