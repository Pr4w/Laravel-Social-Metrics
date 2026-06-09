<?php

namespace Pr4w\SocialMetrics\Concerns;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

trait PoolsRequests
{
    /**
     * Fire one request per key in parallel. The builder receives the pool and
     * each key, and should call $pool->as($key)->...->get(...).
     *
     * Returns responses keyed by your `as()` name. A failed connection yields a
     * Throwable in that slot rather than a Response, so callers must type-check.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function pool(array $keys, callable $build): array
    {
        return Http::pool(function (Pool $pool) use ($keys, $build) {
            foreach ($keys as $key) {
                $build($pool, $key);
            }
        });
    }
}
