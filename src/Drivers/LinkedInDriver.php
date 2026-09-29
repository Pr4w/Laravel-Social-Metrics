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
 * LinkedIn creator posts. nativeId is the full URN (urn:li:share:... or
 * urn:li:ugcPost:...). Where each metric comes from:
 *
 *   - views (impressions), reach, shares: memberCreatorPostAnalytics, one query
 *     per queryType (IMPRESSION, MEMBERS_REACHED, RESHARE).
 *   - likes, comments: socialActions. It needs r_member_social (restricted) or
 *     r_organization_social, so it works for page posts and org-scoped tokens.
 *   - likes, comments fallback: a token for a personal profile only carries
 *     r_member_postAnalytics and gets a 403 from socialActions. For those posts
 *     only, a second pool asks memberCreatorPostAnalytics for REACTION (likes,
 *     all reaction types, like totalLikes) and COMMENT. The analytics can lag
 *     socialActions slightly. The socialActions failure is kept in
 *     raw['socialActions_error'], and an error is only reported when neither
 *     source yields likes or comments.
 *
 * Requests are fired in a pool keyed by "{index}|{what}", then reassembled.
 */
class LinkedInDriver extends AbstractDriver
{
    private const ANALYTICS = ['IMPRESSION', 'MEMBERS_REACHED', 'RESHARE'];

    private const FALLBACK = ['REACTION', 'COMMENT'];

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

        $responses = Http::pool(function (Pool $pool) use ($nativeIds, $token, $headers) {
            foreach ($nativeIds as $i => $urn) {
                $pool->as("{$i}|social")->withToken($token)->withHeaders($headers)
                    ->get('https://api.linkedin.com/rest/socialActions/' . urlencode($urn));

                foreach (self::ANALYTICS as $metric) {
                    $pool->as("{$i}|{$metric}")->withToken($token)->withHeaders($headers)
                        ->get($this->analyticsUrl($urn, $metric));
                }
            }
        });

        // Only posts whose socialActions call failed get the analytics fallback,
        // so page posts and org-scoped tokens spend no extra analytics quota.
        $failed = array_filter(
            $nativeIds,
            fn (string $urn, int|string $i) => ! $this->ok($responses["{$i}|social"] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        $fallback = $failed === [] ? [] : Http::pool(function (Pool $pool) use ($failed, $token, $headers) {
            foreach ($failed as $i => $urn) {
                foreach (self::FALLBACK as $metric) {
                    $pool->as("{$i}|{$metric}")->withToken($token)->withHeaders($headers)
                        ->get($this->analyticsUrl($urn, $metric));
                }
            }
        });

        foreach ($nativeIds as $i => $urn) {
            $raw = [];
            $analytics = [];

            foreach (self::ANALYTICS as $metric) {
                $analytics[$metric] = $this->analyticsCount($responses["{$i}|{$metric}"] ?? null);
            }

            $social = $responses["{$i}|social"] ?? null;

            if ($this->ok($social)) {
                $likes = $this->toInt($social->json('likesSummary.totalLikes'));
                $comments = $this->toInt($social->json('commentsSummary.aggregatedTotalComments'));
                $raw['socialActions'] = $social->json();
            } else {
                $socialError = $this->slotError($social, MetricScope::Post, $urn, 'socialActions');
                $raw['socialActions_error'] = [
                    'status' => $socialError->httpStatus,
                    'reason' => $socialError->reason->value,
                    'message' => $socialError->message,
                ];

                $reaction = $fallback["{$i}|REACTION"] ?? null;
                $likes = $analytics['REACTION'] = $this->analyticsCount($reaction);
                $comments = $analytics['COMMENT'] = $this->analyticsCount($fallback["{$i}|COMMENT"] ?? null);

                if ($likes === null && $comments === null) {
                    // Both sources failed. Prefer a retryable failure so the
                    // caller retries; otherwise report the primary source.
                    $fallbackError = $this->slotError($reaction, MetricScope::Post, $urn, 'memberCreatorPostAnalytics');

                    $result->addError(! $socialError->retryable() && $fallbackError->retryable() ? $fallbackError : $socialError);
                }
            }

            $raw['analytics'] = $analytics;

            $result->addPost(new PostMetrics(
                platform: 'linkedin',
                nativeId: $urn,
                views: $analytics['IMPRESSION'] ?? null,   // impressions used as views
                likes: $likes,
                comments: $comments,
                shares: $analytics['RESHARE'] ?? null,
                reach: $analytics['MEMBERS_REACHED'] ?? null,
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
