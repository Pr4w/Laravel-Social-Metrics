# Laravel Social Metrics

Auth-agnostic social analytics engine: account-level and post-level engagement across Instagram, TikTok, YouTube, LinkedIn and Threads.

It depends on no auth provider. You hand it an access token (inline, or via a resolver you control) and it fetches, normalizes and aggregates metrics. It pairs naturally with `pr4w/laravel-social-tokens`, but that is one option, not a requirement: any token source works.

Return-value-primary: every call gives you a `MetricsResult` synchronously. Events fire in addition, never instead.

## Install

```bash
composer require pr4w/laravel-social-metrics
php artisan vendor:publish --tag=social-metrics-config
```

Only YouTube needs config credentials (it is key-based, no account):

```dotenv
SOCIAL_METRICS_YOUTUBE_KEY=AIza...
SOCIAL_METRICS_YOUTUBE_CHANNEL_ID=UC...
```

## Quick start (single account, you hold the token)

Simplest possible: call a driver directly with a token.

```php
use Pr4w\SocialMetrics\Facades\SocialMetrics;
use Pr4w\SocialMetrics\Support\MetricsContext;

$result = SocialMetrics::driver('instagram')->fetchPostMetrics(
    ['17841400000000001', '17841400000000002'],
    new MetricsContext('instagram', accessToken: $token),
);
```

Or use the orchestrator with the token inline on each ref:

```php
use Pr4w\SocialMetrics\Support\PostRef;

$result = SocialMetrics::fetchPosts([
    PostRef::make('instagram', '178414...', accountId: 'main', accessToken: $token),
]);
```

`nativeId` is the platform id the insights endpoint accepts (media id, video id,
LinkedIn URN). `accountId` is your own label, used to group refs and echoed back
in results and events. It is whatever you want: a primary key, a handle, anything.

## Multi-account: provide a resolver

When you have several accounts, do not put a token on every ref. Give the package
a resolver that turns one of your account labels into a token (and any per-account
`meta` a driver needs). The resolver is the single auth seam, and where you decide
the token's origin.

```php
use Pr4w\SocialMetrics\Support\ResolvedAccount;

// Set once, e.g. in a service provider:
SocialMetrics::resolveAccountsUsing(function (string $platform, string|int|null $accountId) {
    $token = MyTokenStore::tokenFor($platform, $accountId);   // your own store

    if (! $token) {
        return null;          // run continues; this account is flagged needs_reconnect
    }

    return new ResolvedAccount($token, meta: [
        'ig_user_id' => MyTokenStore::igUserId($accountId),   // only what that platform needs
    ]);
});

// Then refs stay lean:
$result = SocialMetrics::fetchPosts([
    PostRef::make('instagram', '178414...', accountId: 1),
    PostRef::make('instagram', '178402...', accountId: 7),   // different account, own token
    PostRef::make('youtube', 'dQw4w9WgXcQ'),                 // key-based, no token needed
    PostRef::make('linkedin', 'urn:li:share:7123...', accountId: 3),
]);
```

You can also pass a resolver per call: `SocialMetrics::fetchPosts($refs, $resolver)`.

In practice you map your stored publications straight into refs:

```php
$refs = $publications->map(fn ($p) => PostRef::make($p->platform, $p->native_id, $p->account_id));
$result = SocialMetrics::fetchPosts($refs);
```

### Example resolver: pr4w/laravel-social-tokens

The package never references social-tokens. If you use it, the resolver is a few lines:

```php
use Pr4w\SocialMetrics\Support\ResolvedAccount;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;

SocialMetrics::resolveAccountsUsing(function (string $platform, string|int|null $accountId) {
    $account = $accountId
        ? SocialAccount::whereKey($accountId)->first()
        : SocialAccount::where('provider', $platform)->first();

    if (! $account) {
        return null;
    }

    try {
        $token = app(SocialTokens::class)->validAccessTokenFor($account);   // refreshes transparently
    } catch (NeedsReconnectException) {
        return null;   // flagged needs_reconnect, run continues
    }

    return new ResolvedAccount($token, meta: [
        'ig_user_id'       => data_get($account->profile, 'ig_user_id'),
        'threads_user_id'  => data_get($account->profile, 'threads_user_id'),
        'channel_id'       => data_get($account->profile, 'channel_id'),
        'organization_urn' => data_get($account->profile, 'organization_urn'),
        'is_person'        => method_exists($account, 'isPerson') ? $account->isPerson() : null,
    ]);
});
```

## Reading the result

```php
$result->successful();                      // bool
$result->posts;                             // Collection<PostMetrics>
$result->errors;                            // Collection<MetricsError>

$m = $result->postFor('instagram', '178414...');
$m->views; $m->likes; $m->comments; $m->shares; $m->saves; $m->reach; $m->raw;
```

`null` on a metric means the platform does not expose it (or hid it). `0` is a real
zero. Do not coalesce one into the other.

## Account metrics

There is no implicit "all accounts": this package owns no account store, so you
enumerate the accounts you care about and hand in `AccountRef`s. Supply the token
inline, or rely on the resolver.

```php
use Pr4w\SocialMetrics\Support\AccountRef;

// Inline tokens
$result = SocialMetrics::fetchAccounts([
    AccountRef::make('instagram', accountId: 1, accessToken: $igToken, meta: ['ig_user_id' => '178...']),
    AccountRef::make('youtube',   accountId: 'main', meta: ['channel_id' => 'UC...']),  // key-based
    AccountRef::make('linkedin',  accountId: 'me', accessToken: $liToken, meta: ['is_person' => true]),
]);

// Or rely on the resolver (omit accessToken/meta)
$result = SocialMetrics::fetchAccounts([
    AccountRef::make('tiktok', accountId: 7),
    AccountRef::make('linkedin', accountId: 3, meta: ['is_person' => false, 'organization_urn' => 'urn:li:organization:123']),
]);

$result->accounts;                          // Collection<AccountMetrics>
$a = $result->accountFor('tiktok', $openId);
$a->followers; $a->following; $a->posts; $a->views;
```

### Per-platform meta keys

A driver reads what it needs from `meta` (resolver or AccountRef), falling back to
config where noted:

| Platform  | meta keys                                  | notes |
|-----------|--------------------------------------------|-------|
| instagram | `ig_user_id`                               | account metrics only; falls back to `drivers.instagram.user_id` |
| facebook  | `page_id`                                  | account metrics only; falls back to accountId. nativeId is `{pageId}_{postId}` |
| threads   | `threads_user_id`                          | account metrics only; falls back to `drivers.threads.user_id` |
| youtube   | `channel_id`                               | key-based; falls back to `drivers.youtube.channel_id` |
| linkedin  | `is_person`, `organization_urn`            | person uses the token owner; org needs the URN |
| tiktok    | (none)                                     | open_id comes back from the API |

## Events

Toggle with `social-metrics.events`. Each carries the relevant DTO and your `accountId`.

- `PostMetricsFetched`
- `AccountMetricsFetched`
- `MetricsFetchFailed`
- `MetricsRunCompleted` (once per run, carries the full `MetricsResult`)

## Errors

Errors are data, not exceptions. Drivers never throw for partial failures: a dead
post becomes a `MetricsError` in `$result->errors` and the run continues, so one
deleted id never sinks the other 49 in a batch. (If you want exception control flow
in a single-item job, derive it: `if (! $error->retryable()) throw new MyException($error->message);`.)

Each `MetricsError` carries a fine-grained `reason` (`ErrorReason`) for logging and a
coarse `category()` (`ErrorCategory`) for decisions:

| category    | meaning                                            | retryable |
|-------------|----------------------------------------------------|-----------|
| `temporary` | throttling, transport blip, server 5xx             | yes       |
| `permanent` | deleted/unsupported object, bad config             | no        |
| `reconnect` | token revoked or expired, account needs re-auth    | no        |
| `unknown`   | the driver could not classify it, review and map   | no        |

Use `retryable()` (true only for `temporary`) rather than hardcoding reasons:

```php
if ($error = $result->errors->first()) {
    if ($error->retryable()) {
        $this->release(120);
        return;
    }

    if ($error->category() === ErrorCategory::Reconnect) {
        // mark the account for re-auth
    }

    Log::warning('Metrics fetch error', [
        'reason' => $error->reason->value, 'category' => $error->category()->value, 'message' => $error->message,
    ]);
    return;
}
```

### Classification is per-platform

Each vendor shapes errors differently, so each driver classifies its own. The Meta
drivers (Instagram, Facebook, Threads) share the `ClassifiesGraphErrors` trait
(Graph `code`/`error_subcode`); YouTube reads Google `error.errors[].reason`; TikTok
reads its string `error.code` (it returns HTTP 200 with an error body); LinkedIn uses
the status-based default. The base `AbstractDriver::classifyError()` is the fallback
every driver defers to for codes it does not recognize, and it sends anything it
cannot place to `unknown`.

That `unknown` bucket is deliberate: an error the package has not been taught yet is
flagged (not silently treated as a generic HTTP error), so you can see it in logs and
add a mapping. Override `classifyError(int $status, array $body): ErrorReason` in your
own driver, or in a subclass registered via `extend()`, to teach it new codes.

## Custom drivers

```php
use Pr4w\SocialMetrics\Contracts\MetricsDriver;

SocialMetrics::extend('x', fn () => new XMetricsDriver());
```

Implement `MetricsDriver`, or extend `AbstractDriver` for the shared HTTP error
mapping, `int()` null-safe casting, and Graph insight flattening. A driver only ever
sees a `MetricsContext` (platform, token, accountId, meta, config); it never knows
where the token came from. X was left out of v1 (no proven fetcher
yet) but drop in this way.

## Notes / verify before production

- **Account-level endpoints** (IG `followers_count`, Threads `threads_insights`,
  TikTok `user/info`, YouTube `channels.statistics`, Facebook page `followers_count`,
  LinkedIn) were written from the
  documented API shapes, not from battle-tested code like the post-level fetchers.
  Confirm field names and permissions against current docs.
- **LinkedIn** branches person vs organization on `meta['is_person']` (inferred from
  the presence of an org URN when absent). Person uses `memberFollowersCount?q=me`;
  organization uses `networkSizes` on `meta['organization_urn']`.
- **TikTok** cannot query by id, so it pages the account listing and filters. Raise
  `drivers.tiktok.max_videos` if you request ids older than the window.
