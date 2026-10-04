<?php

namespace Pr4w\SocialMetrics\Drivers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialMetrics\Data\AccountMetrics;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\PostMetrics;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Support\MetricsContext;

/**
 * LinkedIn posts. nativeId is the full URN (urn:li:share:... or
 * urn:li:ugcPost:...). Metrics come from two sources:
 *
 *   - memberCreatorPostAnalytics, the primary source, one query per queryType:
 *     IMPRESSION (views), MEMBERS_REACHED (reach), RESHARE (shares), REACTION
 *     (likes, all reaction types), COMMENT (comments), POST_SAVE (saves, needs
 *     LinkedIn-Version 202604 or later). Needs r_member_postAnalytics, and only
 *     covers the authenticated member's own posts.
 *   - socialActions, the fallback for likes and comments, asked only for posts
 *     whose REACTION or COMMENT query failed: in practice company page posts.
 *     Needs r_organization_social there (on a member's post it needs the closed
 *     r_member_social, so it is never asked first).
 *
 * A post is returned whenever any source produced a metric; failed sources are
 * left null and recorded in raw. An error is reported, with no PostMetrics, only
 * when nothing came back for that URN.
 *
 * Requests are fired in pools keyed by "{index}|{what}", then reassembled.
 */
class LinkedInDriver extends AbstractDriver
{
    /** memberCreatorPostAnalytics queryType => PostMetrics field. */
    private const ANALYTICS = [
        'IMPRESSION' => 'views',
        'MEMBERS_REACHED' => 'reach',
        'RESHARE' => 'shares',
        'REACTION' => 'likes',
        'COMMENT' => 'comments',
        'POST_SAVE' => 'saves',
    ];

    public function platform(): string
    {
        return 'linkedin';
    }

    private function headers(MetricsContext $context): array
    {
        return [
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version' => $context->config['api_version'] ?? '202605',
        ];
    }

    public function fetchPostMetrics(array $nativeIds, MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $token = $context->accessToken;
        $headers = $this->headers($context);

        $analytics = Http::pool(function (Pool $pool) use ($nativeIds, $token, $headers) {
            foreach ($nativeIds as $i => $urn) {
                foreach (array_keys(self::ANALYTICS) as $queryType) {
                    $pool->as("{$i}|{$queryType}")->withToken($token)->withHeaders($headers)
                        ->get($this->analyticsUrl($urn, $queryType));
                }
            }
        });

        // socialActions only carries likes and comments, so it is only worth
        // asking when one of those two analytics queries failed.
        $fallback = array_filter(
            $nativeIds,
            fn (string $urn, int|string $i) => ! $this->ok($analytics["{$i}|REACTION"] ?? null)
                || ! $this->ok($analytics["{$i}|COMMENT"] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        $social = $fallback === [] ? [] : Http::pool(function (Pool $pool) use ($fallback, $token, $headers) {
            foreach ($fallback as $i => $urn) {
                $pool->as("{$i}|social")->withToken($token)->withHeaders($headers)
                    ->get('https://api.linkedin.com/rest/socialActions/' . urlencode($urn));
            }
        });

        foreach ($nativeIds as $i => $urn) {
            $metrics = [];
            $raw = ['analytics' => []];
            $errors = [];

            foreach (self::ANALYTICS as $queryType => $field) {
                $slot = $analytics["{$i}|{$queryType}"] ?? null;
                $metrics[$field] = $raw['analytics'][$queryType] = $this->analyticsCount($slot);

                if ($metrics[$field] === null) {
                    $errors[] = $this->slotError($slot, MetricScope::Post, $urn, 'memberCreatorPostAnalytics');
                }
            }

            if (array_key_exists($i, $fallback)) {
                $slot = $social["{$i}|social"] ?? null;

                if ($this->ok($slot)) {
                    $metrics['likes'] ??= $this->toInt($slot->json('likesSummary.totalLikes'));
                    $metrics['comments'] ??= $this->toInt($slot->json('commentsSummary.aggregatedTotalComments'));
                    $raw['socialActions'] = $slot->json();
                } else {
                    // Listed first so it is the one reported when nothing is retryable.
                    array_unshift($errors, $socialError = $this->slotError($slot, MetricScope::Post, $urn, 'socialActions'));
                    $raw['socialActions_error'] = [
                        'status' => $socialError->httpStatus,
                        'reason' => $socialError->reason->value,
                        'message' => $socialError->message,
                    ];
                }
            }

            if (array_filter($metrics, fn (?int $value) => $value !== null) === []) {
                // No source produced anything. Prefer a retryable failure so the
                // caller retries; otherwise report socialActions, the last source.
                $result->addError(collect($errors)->first(fn (MetricsError $e) => $e->retryable()) ?? $errors[0]);

                continue;
            }

            $result->addPost(new PostMetrics(
                platform: 'linkedin',
                nativeId: $urn,
                views: $metrics['views'],   // impressions used as views
                likes: $metrics['likes'],
                comments: $metrics['comments'],
                shares: $metrics['shares'],
                saves: $metrics['saves'],
                reach: $metrics['reach'],
                raw: $raw,
                fetchedAt: now()->toImmutable(),
            ));
        }

        return $result;
    }

    private function analyticsUrl(string $urn, string $queryType): string
    {
        $type = str_contains($urn, 'ugcPost') ? 'ugc' : 'share';
        $encoded = urlencode($urn);

        return "https://api.linkedin.com/rest/memberCreatorPostAnalytics?q=entity&entity=({$type}:{$encoded})&queryType={$queryType}";
    }

    /** elements.0.count of an analytics slot: 0 when it has no rows, null when the call failed. */
    private function analyticsCount(mixed $slot): ?int
    {
        return $this->ok($slot) ? $this->toInt($slot->json('elements.0.count') ?? 0) : null;
    }

    private function ok(mixed $slot): bool
    {
        return $slot instanceof Response && $slot->successful();
    }

    /**
     * A missing scope comes back as 403 {"status":403,"code":"ACCESS_DENIED"}.
     * The token is valid, it was just never granted that permission, so this is
     * Permission (never retried) rather than NeedsReconnect. 401, 404, 429 and
     * 5xx keep the status-based default.
     */
    protected function classifyError(int $status, array $body): ErrorReason
    {
        if ($status === 403 || ($body['code'] ?? null) === 'ACCESS_DENIED') {
            return ErrorReason::Permission;
        }

        return parent::classifyError($status, $body);
    }

    /**
     * Account-level followers. Personal profiles use memberFollowersCount?q=me
     * (the token's own member, no identifier needed); organizations use
     * networkSizes on the org URN.
     *
     * Read straight from the supplied URN: only urn:li:person is a person, any
     * other typed entity (organization, school, brand) takes the networkSizes
     * path. If no typed urn:li: identifier is given, it falls back to
     * meta['is_person'], then to whether an entity URN resolves.
     */
    public function fetchAccountMetrics(MetricsContext $context): DriverResult
    {
        return $this->isPerson($context)
            ? $this->personFollowers($context)
            : $this->organizationFollowers($context);
    }

    private function personFollowers(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;

        $response = Http::withToken($context->accessToken)
            ->withHeaders($this->headers($context))
            ->get('https://api.linkedin.com/rest/memberFollowersCount', ['q' => 'me']);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        return $result->setAccount(new AccountMetrics(
            platform: 'linkedin',
            accountId: (string) ($context->accountId ?? 'me'),
            followers: $response->json('elements.0.memberFollowersCount'),
            raw: $response->json() ?? [],
            fetchedAt: now()->toImmutable(),
        ));
    }

    private function organizationFollowers(MetricsContext $context): DriverResult
    {
        $result = new DriverResult;
        $orgUrn = $this->orgUrn($context);

        if (! $orgUrn) {
            return $result->addError(new MetricsError(
                'linkedin', MetricScope::Account, null, ErrorReason::Configuration,
                'Organization account has no URN. Pass the urn:li:organization:… as accountId, or meta[organization_urn] (via AccountRef or resolver).',
            ));
        }

        $response = Http::withToken($context->accessToken)
            ->withHeaders($this->headers($context))
            ->get('https://api.linkedin.com/rest/networkSizes/' . urlencode($orgUrn), [
                'edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER',
            ]);

        if (! $response->successful()) {
            return $result->addError($this->httpError($response, MetricScope::Account));
        }

        return $result->setAccount(new AccountMetrics(
            platform: 'linkedin',
            accountId: (string) $orgUrn,
            followers: $response->json('firstDegreeSize'),
            raw: $response->json() ?? [],
            fetchedAt: now()->toImmutable(),
        ));
    }

    private function isPerson(MetricsContext $context): bool
    {
        // LinkedIn URNs are self-describing. Only urn:li:person is a person;
        // every other typed entity (organization, school, brand, ...) uses the
        // networkSizes path, so default to "not a person" once a URN is typed.
        foreach ($this->urnCandidates($context) as $urn) {
            if (str_contains($urn, 'urn:li:person')) {
                return true;
            }

            if (str_contains($urn, 'urn:li:')) {
                return false;
            }
        }

        // No typed urn:li: identifier: honour an explicit flag, else treat as a
        // person unless an org URN was supplied via meta.
        if (array_key_exists('is_person', $context->meta)) {
            return (bool) $context->meta['is_person'];
        }

        return $this->orgUrn($context) === null;
    }

    /**
     * The entity URN for networkSizes: any non-person LinkedIn entity
     * (organization, school, brand). Prefers an explicit config/meta org URN,
     * then any typed non-person URN passed as an identifier.
     */
    private function orgUrn(MetricsContext $context): ?string
    {
        $candidate = $context->meta['organization_urn'] ?? null;

        if (! $candidate) {
            foreach ($this->urnCandidates($context) as $urn) {
                if (str_contains($urn, 'urn:li:') && ! str_contains($urn, 'urn:li:person')) {
                    $candidate = $urn;
                    break;
                }
            }
        }

        return $candidate ? (string) $candidate : null;
    }

    /**
     * @return list<string>
     */
    private function urnCandidates(MetricsContext $context): array
    {
        $candidates = [
            $context->accountId,
            $context->meta['urn'] ?? null,
            $context->meta['organization_urn'] ?? null,
        ];

        return array_values(array_filter($candidates, 'is_string'));
    }
}
