<?php

namespace Pr4w\SocialMetrics;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Pr4w\SocialMetrics\Contracts\MetricsDriver;
use Pr4w\SocialMetrics\Contracts\TokenResolver;
use Pr4w\SocialMetrics\Data\DriverResult;
use Pr4w\SocialMetrics\Data\MetricsError;
use Pr4w\SocialMetrics\Data\MetricsResult;
use Pr4w\SocialMetrics\Enums\ErrorReason;
use Pr4w\SocialMetrics\Enums\MetricScope;
use Pr4w\SocialMetrics\Events\AccountMetricsFetched;
use Pr4w\SocialMetrics\Events\MetricsFetchFailed;
use Pr4w\SocialMetrics\Events\MetricsRunCompleted;
use Pr4w\SocialMetrics\Events\PostMetricsFetched;
use Pr4w\SocialMetrics\Support\AccountRef;
use Pr4w\SocialMetrics\Support\MetricsContext;
use Pr4w\SocialMetrics\Support\PostRef;
use Pr4w\SocialMetrics\Support\ResolvedAccount;
use Throwable;

/**
 * Groups refs by platform + account, dispatches to drivers, aggregates results
 * and fires events. Auth-agnostic: tokens come from the ref/account itself or
 * from a caller-supplied resolver. This package depends on no auth provider.
 */
class MetricsOrchestrator
{
    private TokenResolver|Closure|null $resolver = null;

    public function __construct(
        private DriverManager $drivers,
        private Dispatcher $events,
    ) {}

    public function driver(string $platform): MetricsDriver
    {
        return $this->drivers->driver($platform);
    }

    public function extend(string $platform, Closure $callback): self
    {
        $this->drivers->extend($platform, $callback);

        return $this;
    }

    /** Set a default token resolver used when a ref/account has no inline token. */
    public function resolveAccountsUsing(TokenResolver|Closure $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * Fetch post-level metrics.
     *
     * Refs are grouped by platform, then by account: each account has its own
     * token and batch endpoints take one token per request, so account is the
     * grouping unit. A token is taken from the ref's accessToken if present,
     * otherwise from the resolver.
     *
     * @param  iterable<PostRef>  $refs
     */
    public function fetchPosts(iterable $refs, TokenResolver|Closure|null $resolver = null): MetricsResult
    {
        $result = new MetricsResult;
        $resolver ??= $this->resolver;

        foreach (collect($refs)->groupBy('platform') as $platform => $platformRefs) {
            $driver = $this->resolveDriver($platform);

            if (! $driver) {
                $this->record($result, $this->configError($platform, MetricScope::Post, "No driver registered for [{$platform}]."));

                continue;
            }

            $groups = $platformRefs->groupBy(fn (PostRef $r) => (string) ($r->accountId ?? $r->accessToken ?? ''));

            foreach ($groups as $group) {
                /** @var PostRef $first */
                $first = $group->first();
                $ids = $group->pluck('nativeId')->unique()->values()->all();

                $context = $this->buildContext(
                    $platform, $driver, $first->accountId, $first->accessToken, $first->meta, $resolver, $result, MetricScope::Post,
                );

                if ($context === null) {
                    continue; // auth/config failure already recorded
                }

                $this->runPostFetch($driver, $ids, $context, $result);
            }
        }

        $this->dispatch(new MetricsRunCompleted($result));

        return $result;
    }

    /**
     * Fetch account-level metrics for the given accounts. There is no implicit
     * "all accounts": enumerate the accounts you care about yourself and hand
     * them in, since this package does not own an account store.
     *
     * @param  iterable<AccountRef>  $accounts
     */
    public function fetchAccounts(iterable $accounts, TokenResolver|Closure|null $resolver = null): MetricsResult
    {
        $result = new MetricsResult;
        $resolver ??= $this->resolver;

        foreach ($accounts as $ref) {
            $driver = $this->resolveDriver($ref->platform);

            if (! $driver || ! $driver->supportsAccountMetrics()) {
                continue;
            }

            $context = $this->buildContext(
                $ref->platform, $driver, $ref->accountId, $ref->accessToken, $ref->meta, $resolver, $result, MetricScope::Account,
            );

            if ($context === null) {
                continue;
            }

            try {
                $dr = $driver->fetchAccountMetrics($context);
            } catch (Throwable $e) {
                $this->record($result, new MetricsError(
                    $ref->platform, MetricScope::Account, null, ErrorReason::DriverError, $e->getMessage(),
                ), $ref->accountId);

                continue;
            }

            $this->mergeAccount($dr, $ref->accountId, $result);
        }

        $this->dispatch(new MetricsRunCompleted($result));

        return $result;
    }

    /**
     * Build a context, resolving a token where the driver needs one. Returns
     * null (and records an error) when auth cannot be satisfied.
     */
    private function buildContext(
        string $platform,
        MetricsDriver $driver,
        string|int|null $accountId,
        ?string $inlineToken,
        array $inlineMeta,
        TokenResolver|Closure|null $resolver,
        MetricsResult $result,
        MetricScope $scope,
    ): ?MetricsContext {
        $config = $this->driverConfig($platform);

        // Key-based drivers (e.g. YouTube): no token. A resolver may still
        // provide meta (channel_id, etc.) but is never required.
        if (! $driver->requiresAccount()) {
            $meta = $inlineMeta;

            if ($inlineToken === null && $inlineMeta === [] && $resolver) {
                $resolved = $this->tryResolve($resolver, $platform, $accountId);
                $meta = $resolved?->meta ?? [];
            }

            return new MetricsContext($platform, $inlineToken, $accountId, $meta, $config);
        }

        if ($inlineToken !== null) {
            return new MetricsContext($platform, $inlineToken, $accountId, $inlineMeta, $config);
        }

        $label = $accountId ?? 'default';

        if (! $resolver) {
            $this->record($result, $this->configError(
                $platform, $scope,
                "No access token for [{$platform}] account [{$label}]. Provide an inline accessToken or a token resolver.",
            ), $accountId);

            return null;
        }

        try {
            $resolved = $this->invokeResolver($resolver, $platform, $accountId);
        } catch (Throwable $e) {
            $this->record($result, new MetricsError(
                $platform, MetricScope::Account, null, ErrorReason::NeedsReconnect, $e->getMessage(),
            ), $accountId);

            return null;
        }

        if ($resolved === null || $resolved->accessToken === null) {
            $this->record($result, new MetricsError(
                $platform, MetricScope::Account, null, ErrorReason::NeedsReconnect,
                "No valid token resolved for [{$platform}] account [{$label}].",
            ), $accountId);

            return null;
        }

        return new MetricsContext($platform, $resolved->accessToken, $accountId, $resolved->meta ?: $inlineMeta, $config);
    }

    private function tryResolve(TokenResolver|Closure $resolver, string $platform, string|int|null $accountId): ?ResolvedAccount
    {
        try {
            return $this->invokeResolver($resolver, $platform, $accountId);
        } catch (Throwable) {
            return null;
        }
    }

    private function invokeResolver(TokenResolver|Closure $resolver, string $platform, string|int|null $accountId): ?ResolvedAccount
    {
        return $resolver instanceof TokenResolver
            ? $resolver->resolve($platform, $accountId)
            : ($resolver)($platform, $accountId);
    }

    private function runPostFetch(MetricsDriver $driver, array $ids, MetricsContext $context, MetricsResult $result): void
    {
        if (empty($ids)) {
            return;
        }

        try {
            $dr = $driver->fetchPostMetrics($ids, $context);
        } catch (Throwable $e) {
            $this->record($result, new MetricsError(
                $context->platform, MetricScope::Post, null, ErrorReason::DriverError, $e->getMessage(),
            ), $context->accountId);

            return;
        }

        $this->mergePosts($dr, $context, $result);
    }

    private function resolveDriver(string $platform): ?MetricsDriver
    {
        try {
            return $this->drivers->driver($this->driverNameFor($platform));
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    private function driverNameFor(string $provider): string
    {
        return config("social-metrics.driver_map.{$provider}", $provider);
    }

    private function driverConfig(string $platform): array
    {
        return config("social-metrics.drivers.{$platform}", []);
    }

    private function mergePosts(DriverResult $dr, MetricsContext $context, MetricsResult $result): void
    {
        foreach ($dr->posts as $metrics) {
            $result->posts->push($metrics);
            $this->dispatch(new PostMetricsFetched($metrics, $context->accountId));
        }

        foreach ($dr->errors as $error) {
            $result->errors->push($error);
            $this->dispatch(new MetricsFetchFailed($error, $context->accountId));
        }
    }

    private function mergeAccount(DriverResult $dr, string|int|null $accountId, MetricsResult $result): void
    {
        if ($dr->account) {
            $result->accounts->push($dr->account);
            $this->dispatch(new AccountMetricsFetched($dr->account, $accountId));
        }

        foreach ($dr->errors as $error) {
            $result->errors->push($error);
            $this->dispatch(new MetricsFetchFailed($error, $accountId));
        }
    }

    private function configError(string $platform, MetricScope $scope, string $message): MetricsError
    {
        return new MetricsError($platform, $scope, null, ErrorReason::Configuration, $message);
    }

    private function record(MetricsResult $result, MetricsError $error, string|int|null $accountId = null): void
    {
        $result->errors->push($error);
        $this->dispatch(new MetricsFetchFailed($error, $accountId));
    }

    private function dispatch(object $event): void
    {
        if (config('social-metrics.events', true)) {
            $this->events->dispatch($event);
        }
    }
}
