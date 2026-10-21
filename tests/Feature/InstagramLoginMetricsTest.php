<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\MetricsResult;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\AccountRef;
use Pr4w\SocialMetrics\Support\PostRef;

const IGL_MEDIA = '18000000000000001';
const IGL_OTHER = '18000000000000002';
const IGL_STORY = '18000000000000003';
const IGL_USER = '17841400000000099';

/** Graph insight rows for the given name => value pairs. */
function iglInsights(array $values): array
{
    return ['data' => array_map(
        fn (string $name, mixed $value) => ['name' => $name, 'period' => 'lifetime', 'values' => [['value' => $value]], 'id' => "x/insights/{$name}/lifetime"],
        array_keys($values),
        $values,
    )];
}

function iglMedia(int $seed = 1): array
{
    return [200, iglInsights([
        'views' => 900 * $seed,
        'reach' => 600 * $seed,
        'likes' => 45 * $seed,
        'comments' => 6 * $seed,
        'shares' => 3 * $seed,
        'saved' => 8 * $seed,
        'total_interactions' => 62 * $seed,
    ])];
}

function iglError(int $code, string $message, ?int $subcode = null): array
{
    return [400, ['error' => array_filter([
        'message' => $message,
        'type' => 'OAuthException',
        'code' => $code,
        'error_subcode' => $subcode,
        'fbtrace_id' => 'trace',
    ])]];
}

/**
 * Fake graph.instagram.com. $media maps a media id to its insights answer
 * ([status, body]); an unknown id is a deleted media. $account answers the
 * account call. graph.facebook.com is not faked, so the test fails if any
 * request goes there (TestCase prevents stray requests).
 */
function fakeInstagramLogin(array $media = [], ?array $account = null): void
{
    Http::fake(['graph.instagram.com/*' => function (Request $request) use ($media, $account) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('#^/v[\d.]+/(\d+)/insights$#', $path, $m)) {
            [$status, $body] = $media[$m[1]] ?? iglError(100, "Unsupported get request. Object with ID '{$m[1]}' does not exist", 33);

            return Http::response($body, $status);
        }

        if ($account && preg_match('#^/v[\d.]+/\d+$#', $path)) {
            return Http::response($account[1], $account[0]);
        }

        return Http::response([], 404);
    }]);
}

function fetchInstagramLogin(array $ids): MetricsResult
{
    return SocialMetrics::fetchPosts(array_map(
        fn (string $id) => PostRef::make('instagram_login', $id, accountId: 1, accessToken: 'IGAA-token'),
        $ids,
    ));
}

/** @return list<Request> */
function iglRequests(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
}

it('reads each media with its own GET on graph.instagram.com, without a batch', function () {
    fakeInstagramLogin([IGL_MEDIA => iglMedia(), IGL_OTHER => iglMedia(2)]);

    $result = fetchInstagramLogin([IGL_MEDIA, IGL_OTHER, IGL_MEDIA]);
    $requests = iglRequests();

    expect($result->errors)->toBeEmpty()
        ->and($result->posts)->toHaveCount(2)
        ->and($requests)->toHaveCount(2)
        ->and(collect($requests)->every(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://graph.instagram.com/v25.0/')
            && $r->data() === ['metric' => 'views,reach,likes,comments,shares,saved,total_interactions', 'access_token' => 'IGAA-token']
        ))->toBeTrue()
        ->and(collect($requests)->map(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH))->sort()->values()->all())
        ->toBe(['/v25.0/'.IGL_MEDIA.'/insights', '/v25.0/'.IGL_OTHER.'/insights']);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'));
});

it('maps the insights onto PostMetrics under the instagram_login platform', function () {
    fakeInstagramLogin([IGL_MEDIA => iglMedia()]);

    $post = fetchInstagramLogin([IGL_MEDIA])->postFor('instagram_login', IGL_MEDIA);

    expect($post->platform)->toBe('instagram_login')
        ->and($post->views)->toBe(900)
        ->and($post->reach)->toBe(600)
        ->and($post->likes)->toBe(45)
        ->and($post->comments)->toBe(6)
        ->and($post->shares)->toBe(3)
        ->and($post->saves)->toBe(8)
        ->and($post->raw['total_interactions'])->toBe(62);
});

it('gives a failed media its own error without stopping the others', function () {
    fakeInstagramLogin([IGL_MEDIA => iglMedia()]);   // IGL_OTHER is deleted

    $result = fetchInstagramLogin([IGL_MEDIA, IGL_OTHER]);
    $error = $result->errors->sole();

    expect($result->postFor('instagram_login', IGL_MEDIA)->likes)->toBe(45)
        ->and($error->nativeId)->toBe(IGL_OTHER)
        ->and($error->platform)->toBe('instagram_login')
        ->and($error->reason)->toBe(ErrorReason::NotFound);
});

it('does not crash on a story id, whose metrics do not exist', function () {
    fakeInstagramLogin([
        IGL_MEDIA => iglMedia(),
        IGL_STORY => iglError(100, '(#100) The Media Insights API does not support the likes metric for this media product type.'),
    ]);

    $result = fetchInstagramLogin([IGL_MEDIA, IGL_STORY]);
    $error = $result->errors->sole();

    expect($result->postFor('instagram_login', IGL_MEDIA))->not->toBeNull()
        ->and($error->nativeId)->toBe(IGL_STORY)
        ->and($error->reason)->toBe(ErrorReason::Configuration);
});

it('classifies graph.instagram.com errors', function (array $answer, ErrorReason $reason, bool $retryable) {
    fakeInstagramLogin([IGL_MEDIA => $answer]);

    $error = fetchInstagramLogin([IGL_MEDIA])->errors->sole();

    expect($error->reason)->toBe($reason)
        ->and($error->retryable())->toBe($retryable);
})->with([
    'invalid token' => [iglError(190, 'Error validating access token: Session has expired.', 463), ErrorReason::NeedsReconnect, false],
    'app throttled' => [iglError(4, 'Application request limit reached'), ErrorReason::RateLimited, true],
    'account throttled' => [iglError(80002, 'There have been too many calls to this Instagram account.'), ErrorReason::RateLimited, true],
    'deleted media' => [iglError(100, "Unsupported get request. Object with ID '18000000000000001' does not exist", 33), ErrorReason::NotFound, false],
]);

it('reads account metrics on graph.instagram.com', function () {
    fakeInstagramLogin(account: [200, ['followers_count' => 1500, 'follows_count' => 120, 'media_count' => 88, 'id' => IGL_USER]]);

    $result = SocialMetrics::fetchAccounts([AccountRef::make('instagram_login', IGL_USER, 'IGAA-token')]);
    $account = $result->accountFor('instagram_login', IGL_USER);

    expect($result->errors)->toBeEmpty()
        ->and($account->followers)->toBe(1500)
        ->and($account->following)->toBe(120)
        ->and($account->posts)->toBe(88);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.instagram.com/v25.0/'.IGL_USER.'?')
        && $r->data() === ['fields' => 'followers_count,follows_count,media_count', 'access_token' => 'IGAA-token']);
});

it('prefers meta ig_user_id over an accountId that is a database key', function () {
    fakeInstagramLogin(account: [200, ['followers_count' => 10]]);

    $result = SocialMetrics::fetchAccounts([AccountRef::make('instagram_login', 7, 'IGAA-token', meta: ['ig_user_id' => IGL_USER])]);

    expect($result->accountFor('instagram_login', IGL_USER)->followers)->toBe(10);
    Http::assertSent(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/v25.0/'.IGL_USER);
});

it('reports a missing account id as Configuration, without a request', function () {
    fakeInstagramLogin();

    $error = SocialMetrics::fetchAccounts([AccountRef::make('instagram_login', accessToken: 'IGAA-token')])->errors->sole();

    expect($error->reason)->toBe(ErrorReason::Configuration)
        ->and($error->platform)->toBe('instagram_login')
        ->and($error->message)->toContain('ig_user_id');
    Http::assertNothingSent();
});

it('uses the configured graph_version', function () {
    config(['social-metrics.drivers.instagram_login.graph_version' => 'v23.0']);
    fakeInstagramLogin([IGL_MEDIA => iglMedia()]);

    fetchInstagramLogin([IGL_MEDIA]);

    Http::assertSent(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/v23.0/'.IGL_MEDIA.'/insights');
});

it('falls back to v25.0 when the app config has no instagram_login block', function () {
    // A config published before this driver replaces the whole drivers array.
    config(['social-metrics.drivers' => ['instagram' => ['graph_version' => 'v25.0']]]);
    fakeInstagramLogin([IGL_MEDIA => iglMedia()]);

    fetchInstagramLogin([IGL_MEDIA]);

    Http::assertSent(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/v25.0/'.IGL_MEDIA.'/insights');
});
