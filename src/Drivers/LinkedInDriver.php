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
 * urn:li:ugcPost:...). Likes + comments come from socialActions; impressions,
 * reach and reshares from memberCreatorPostAnalytics (one query per metric).
 *
 * All requests across all posts are fired in a single pool, keyed by
 * "{index}|{what}", then reassembled.
 */
class LinkedInDriver extends AbstractDriver
{
    private const ANALYTICS = ['IMPRESSION', 'MEMBERS_REACHED', 'RESHARE'];

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
                $encoded = urlencode($urn);
                $type = str_contains($urn, 'ugcPost') ? 'ugc' : 'share';

                $pool->as("{$i}|social")->withToken($token)->withHeaders($headers)
                    ->get("https://api.linkedin.com/rest/socialActions/{$encoded}");

                foreach (self::ANALYTICS as $metric) {
                    $pool->as("{$i}|{$metric}")->withToken($token)->withHeaders($headers)
                        ->get("https://api.linkedin.com/rest/memberCreatorPostAnalytics?q=entity&entity=({$type}:{$encoded})&queryType={$metric}");
                }
            }
        });

        foreach ($nativeIds as $i => $urn) {
            $raw = [];
            $likes = $comments = null;

            $social = $responses["{$i}|social"] ?? null;

            if ($social instanceof Response && $social->successful()) {
                $likes = $social->json('likesSummary.totalLikes');
                $comments = $social->json('commentsSummary.aggregatedTotalComments');
                $raw['socialActions'] = $social->json();
            } else {
                $result->addError($this->slotError($social, MetricScope::Post, $urn, 'socialActions'));
            }

            $analytics = [];

            foreach (self::ANALYTICS as $metric) {
                $r = $responses["{$i}|{$metric}"] ?? null;
                $analytics[$metric] = ($r instanceof Response && $r->successful())
                    ? ($r->json('elements.0.count') ?? 0)
                    : null;
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
                'Organization account has no URN. Provide meta[organization_urn] (via AccountRef or resolver) or drivers.linkedin.organization_urn.',
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
        // person unless an entity URN is resolvable (e.g. from config).
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
        $candidate = $context->meta['organization_urn']
            ?? ($context->config['organization_urn'] ?? null);

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
