<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\MetricsResult;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\PostRef;

const PERSONAL_URN = 'urn:li:share:7100000000000000001';
const PAGE_URN = 'urn:li:ugcPost:7100000000000000002';

/** The 403 LinkedIn returns to a personal-profile token on socialActions. */
function denied(): array
{
    return [403, [
        'status' => 403,
        'code' => 'ACCESS_DENIED',
        'message' => 'Not enough permissions to access: partnerApiSocialActions.GET.20260501',
    ]];
}

function count_(int $count): array
{
    return [200, ['elements' => [['count' => $count]]]];
}

function socialActions(int $likes, int $comments): array
{
    return [200, [
        'likesSummary' => ['totalLikes' => $likes],
        'commentsSummary' => ['aggregatedTotalComments' => $comments],
    ]];
}

/**
 * Fake the LinkedIn REST API. Each route maps a URN to its socialActions
 * response and its analytics responses by queryType, as [status, body] pairs.
 *
 * @param  array<string, array{social: array, analytics: array<string, array>}>  $routes
 */
function fakeLinkedIn(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $url = urldecode($request->url());

        foreach ($routes as $urn => $route) {
            if (! str_contains($url, $urn)) {
                continue;
            }

            if (str_contains($url, '/rest/socialActions/')) {
                [$status, $body] = $route['social'];
            } else {
                preg_match('/queryType=(\w+)/', $url, $m);
                [$status, $body] = $route['analytics'][$m[1]] ?? [500, []];
            }

            return Http::response($body, $status);
        }

        return Http::response([], 404);
    });
}

function fetchLinkedIn(string ...$urns): MetricsResult
{
    return SocialMetrics::fetchPosts(array_map(
        fn (string $urn) => PostRef::make('linkedin', $urn, accountId: 1, accessToken: 'token'),
        $urns,
    ));
}

function sentFallback(string $urn): bool
{
    return Http::recorded(fn (Request $r) => str_contains(urldecode($r->url()), $urn)
        && preg_match('/queryType=(REACTION|COMMENT)/', $r->url()))->isNotEmpty();
}

$analyticsOk = [
    'IMPRESSION' => count_(753),
    'MEMBERS_REACHED' => count_(410),
    'RESHARE' => count_(2),
    'REACTION' => count_(15),
    'COMMENT' => count_(4),
];

it('falls back to analytics for likes and comments when socialActions is refused', function () use ($analyticsOk) {
    fakeLinkedIn([PERSONAL_URN => ['social' => denied(), 'analytics' => $analyticsOk]]);

    $result = fetchLinkedIn(PERSONAL_URN);
    $post = $result->postFor('linkedin', PERSONAL_URN);

    expect($result->errors)->toBeEmpty()
        ->and($post->views)->toBe(753)
        ->and($post->reach)->toBe(410)
        ->and($post->shares)->toBe(2)
        ->and($post->likes)->toBe(15)
        ->and($post->comments)->toBe(4)
        ->and($post->raw['socialActions_error'])->toMatchArray(['status' => 403, 'reason' => 'permission'])
        ->and($post->raw['analytics'])->toMatchArray(['REACTION' => 15, 'COMMENT' => 4]);
});

it('uses socialActions and makes no fallback call when the token has the scope', function () use ($analyticsOk) {
    fakeLinkedIn([PAGE_URN => ['social' => socialActions(30, 7), 'analytics' => $analyticsOk]]);

    $result = fetchLinkedIn(PAGE_URN);
    $post = $result->postFor('linkedin', PAGE_URN);

    expect($result->errors)->toBeEmpty()
        ->and($post->likes)->toBe(30)
        ->and($post->comments)->toBe(7)
        ->and($post->views)->toBe(753)
        ->and($post->raw)->not->toHaveKey('socialActions_error')
        ->and(sentFallback(PAGE_URN))->toBeFalse();
});

it('only sends the fallback for the posts whose socialActions failed', function () use ($analyticsOk) {
    fakeLinkedIn([
        PERSONAL_URN => ['social' => denied(), 'analytics' => $analyticsOk],
        PAGE_URN => ['social' => socialActions(30, 7), 'analytics' => $analyticsOk],
    ]);

    $result = fetchLinkedIn(PERSONAL_URN, PAGE_URN);

    expect($result->errors)->toBeEmpty()
        ->and($result->postFor('linkedin', PERSONAL_URN)->likes)->toBe(15)
        ->and($result->postFor('linkedin', PAGE_URN)->likes)->toBe(30)
        ->and(sentFallback(PERSONAL_URN))->toBeTrue()
        ->and(sentFallback(PAGE_URN))->toBeFalse();
});

it('reports one permission error when socialActions and the fallback are both refused', function () {
    fakeLinkedIn([PERSONAL_URN => ['social' => denied(), 'analytics' => [
        'IMPRESSION' => count_(753),
        'MEMBERS_REACHED' => count_(410),
        'RESHARE' => count_(2),
        'REACTION' => denied(),
        'COMMENT' => denied(),
    ]]]);

    $result = fetchLinkedIn(PERSONAL_URN);
    $error = $result->errors->sole();
    $post = $result->postFor('linkedin', PERSONAL_URN);

    expect($error->reason)->toBe(ErrorReason::Permission)
        ->and($error->retryable())->toBeFalse()
        ->and($error->scope)->toBe(MetricScope::Post)
        ->and($error->nativeId)->toBe(PERSONAL_URN)
        ->and($error->httpStatus)->toBe(403)
        ->and($post->likes)->toBeNull()
        ->and($post->comments)->toBeNull()
        ->and($post->views)->toBe(753);
});

it('keeps likes when only the comment fallback fails, without an error', function () use ($analyticsOk) {
    fakeLinkedIn([PERSONAL_URN => ['social' => denied(), 'analytics' => ['COMMENT' => [503, []]] + $analyticsOk]]);

    $result = fetchLinkedIn(PERSONAL_URN);
    $post = $result->postFor('linkedin', PERSONAL_URN);

    expect($result->errors)->toBeEmpty()
        ->and($post->likes)->toBe(15)
        ->and($post->comments)->toBeNull()
        ->and($post->raw['analytics']['COMMENT'])->toBeNull();
});

it('keeps throttling and server errors retryable', function (array $social, array $fallback, ErrorReason $reason) use ($analyticsOk) {
    fakeLinkedIn([PERSONAL_URN => ['social' => $social, 'analytics' => [
        'REACTION' => $fallback,
        'COMMENT' => $fallback,
    ] + $analyticsOk]]);

    $error = fetchLinkedIn(PERSONAL_URN)->errors->sole();

    expect($error->reason)->toBe($reason)
        ->and($error->retryable())->toBeTrue();
})->with([
    'both rate limited' => [[429, []], [429, []], ErrorReason::RateLimited],
    'both server errors' => [[503, []], [503, []], ErrorReason::HttpError],
    'refused, then rate limited' => [denied(), [429, []], ErrorReason::RateLimited],
]);
