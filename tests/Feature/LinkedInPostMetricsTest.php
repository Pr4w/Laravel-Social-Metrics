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
const BROKEN_URN = 'urn:li:share:7100000000000000003';

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

/** Analytics for a member's own post: every queryType answers. */
function memberAnalytics(): array
{
    return [
        'IMPRESSION' => count_(753),
        'MEMBERS_REACHED' => count_(410),
        'RESHARE' => count_(2),
        'REACTION' => count_(15),
        'COMMENT' => count_(4),
        'POST_SAVE' => count_(3),
    ];
}

/**
 * Fake the LinkedIn REST API. Each route maps a URN to its socialActions
 * response and its analytics responses by queryType ('*' for any other), as
 * [status, body] pairs.
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
                [$status, $body] = $route['analytics'][$m[1]] ?? $route['analytics']['*'] ?? [500, []];
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

function sentSocialActions(string $urn): bool
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), '/rest/socialActions/' . urlencode($urn)))
        ->isNotEmpty();
}

it('reads every metric of a personal post from analytics, without calling socialActions', function () {
    fakeLinkedIn([PERSONAL_URN => ['social' => denied(), 'analytics' => memberAnalytics()]]);

    $result = fetchLinkedIn(PERSONAL_URN);
    $post = $result->postFor('linkedin', PERSONAL_URN);

    expect($result->errors)->toBeEmpty()
        ->and($post->views)->toBe(753)
        ->and($post->reach)->toBe(410)
        ->and($post->shares)->toBe(2)
        ->and($post->likes)->toBe(15)
        ->and($post->comments)->toBe(4)
        ->and($post->saves)->toBe(3)
        ->and($post->raw['analytics'])->toMatchArray(['REACTION' => 15, 'COMMENT' => 4, 'POST_SAVE' => 3])
        ->and($post->raw)->not->toHaveKey('socialActions')
        ->and(sentSocialActions(PERSONAL_URN))->toBeFalse();
});

it('falls back to socialActions for a page post the analytics do not cover', function (array $analyticsFailure) {
    fakeLinkedIn([PAGE_URN => ['social' => socialActions(30, 7), 'analytics' => ['*' => $analyticsFailure]]]);

    $result = fetchLinkedIn(PAGE_URN);
    $post = $result->postFor('linkedin', PAGE_URN);

    expect($result->errors)->toBeEmpty()
        ->and($post->likes)->toBe(30)
        ->and($post->comments)->toBe(7)
        ->and($post->views)->toBeNull()
        ->and($post->reach)->toBeNull()
        ->and($post->shares)->toBeNull()
        ->and($post->saves)->toBeNull()
        ->and($post->raw['socialActions']['likesSummary']['totalLikes'])->toBe(30);
})->with([
    'analytics refused' => [[403, ['status' => 403, 'code' => 'ACCESS_DENIED']]],
    'analytics not found' => [[404, []]],
]);

it('reports one error and no metrics when every source fails', function () {
    fakeLinkedIn([BROKEN_URN => ['social' => denied(), 'analytics' => ['*' => [403, ['code' => 'ACCESS_DENIED']]]]]);

    $result = fetchLinkedIn(BROKEN_URN);
    $error = $result->errors->sole();

    expect($result->posts)->toBeEmpty()
        ->and($error->reason)->toBe(ErrorReason::Permission)
        ->and($error->retryable())->toBeFalse()
        ->and($error->scope)->toBe(MetricScope::Post)
        ->and($error->nativeId)->toBe(BROKEN_URN)
        ->and($error->httpStatus)->toBe(403)
        ->and($error->message)->toContain('partnerApiSocialActions');
});

it('resolves each URN of a mixed batch on its own', function () {
    fakeLinkedIn([
        PERSONAL_URN => ['social' => denied(), 'analytics' => memberAnalytics()],
        PAGE_URN => ['social' => socialActions(30, 7), 'analytics' => ['*' => [403, []]]],
        BROKEN_URN => ['social' => denied(), 'analytics' => ['*' => [403, []]]],
    ]);

    $result = fetchLinkedIn(PERSONAL_URN, PAGE_URN, BROKEN_URN);

    expect($result->postFor('linkedin', PERSONAL_URN)->likes)->toBe(15)
        ->and($result->postFor('linkedin', PAGE_URN)->likes)->toBe(30)
        ->and($result->postFor('linkedin', BROKEN_URN))->toBeNull()
        ->and($result->errors->sole()->nativeId)->toBe(BROKEN_URN)
        ->and(sentSocialActions(PERSONAL_URN))->toBeFalse()
        ->and(sentSocialActions(PAGE_URN))->toBeTrue();
});

it('keeps the post without an error when only some analytics fail', function () {
    // POST_SAVE needs LinkedIn-Version 202604+; an older version answers 400.
    fakeLinkedIn([PERSONAL_URN => ['social' => denied(), 'analytics' => ['POST_SAVE' => [400, []]] + memberAnalytics()]]);

    $result = fetchLinkedIn(PERSONAL_URN);
    $post = $result->postFor('linkedin', PERSONAL_URN);

    expect($result->errors)->toBeEmpty()
        ->and($post->saves)->toBeNull()
        ->and($post->likes)->toBe(15)
        ->and(sentSocialActions(PERSONAL_URN))->toBeFalse();
});

it('builds the entity parameter from the URN type', function () {
    fakeLinkedIn([
        PERSONAL_URN => ['social' => denied(), 'analytics' => memberAnalytics()],
        PAGE_URN => ['social' => denied(), 'analytics' => memberAnalytics()],
    ]);

    fetchLinkedIn(PERSONAL_URN, PAGE_URN);

    $entities = Http::recorded()
        ->map(fn (array $pair) => urldecode($pair[0]->url()))
        ->filter(fn (string $url) => str_contains($url, 'memberCreatorPostAnalytics'));

    expect($entities->filter(fn ($url) => str_contains($url, 'entity=(share:' . PERSONAL_URN . ')')))->toHaveCount(6)
        ->and($entities->filter(fn ($url) => str_contains($url, 'entity=(ugc:' . PAGE_URN . ')')))->toHaveCount(6);
});

it('keeps throttling and server errors retryable', function (array $analyticsFailure, array $social, ErrorReason $reason) {
    fakeLinkedIn([BROKEN_URN => ['social' => $social, 'analytics' => ['*' => $analyticsFailure]]]);

    $error = fetchLinkedIn(BROKEN_URN)->errors->sole();

    expect($error->reason)->toBe($reason)
        ->and($error->retryable())->toBeTrue();
})->with([
    'both rate limited' => [[429, []], [429, []], ErrorReason::RateLimited],
    'both server errors' => [[503, []], [503, []], ErrorReason::HttpError],
    'analytics throttled, socialActions refused' => [[429, []], denied(), ErrorReason::RateLimited],
]);
