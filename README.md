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
in results and events — for **post** metrics it is whatever you want: a primary key,
a handle, anything. For **account** metrics there is no separate `nativeId`, so
`accountId` doubles as the platform-native account id (see
[How identifiers work](#how-identifiers-work-accountid-first-meta-for-the-rest)).

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

    // If your accountId already IS the platform-native id (IG user id, channel id,
    // LinkedIn URN, ...), just return the token — nothing else is needed:
    return new ResolvedAccount($token);

    // --- or, if accountId is a DB key, alias the real native id per platform ---
    // (only include the keys for platforms this account actually uses):
    // return new ResolvedAccount($token, meta: [
    //     'ig_user_id'       => data_get($account->profile, 'ig_user_id'),
    //     'threads_user_id'  => data_get($account->profile, 'threads_user_id'),
    //     'channel_id'       => data_get($account->profile, 'channel_id'),
    //     'organization_urn' => data_get($account->profile, 'organization_urn'), // urn:li:organization:...
    // ]);
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

// Pass the platform-native id as accountId — no meta needed
$result = SocialMetrics::fetchAccounts([
    AccountRef::make('instagram', accountId: '17841400000000001', accessToken: $igToken),
    AccountRef::make('youtube',   accountId: 'UC...'),                                  // key-based
    AccountRef::make('linkedin',  accountId: 'urn:li:person:abc123', accessToken: $liToken),
    AccountRef::make('linkedin',  accountId: 'urn:li:organization:123', accessToken: $liToken),
]);

// meta only when your accountId is a DB key (alias the real id), or rely on the resolver
$result = SocialMetrics::fetchAccounts([
    AccountRef::make('tiktok', accountId: 7),                                           // resolver maps 7 -> token
    AccountRef::make('instagram', accountId: 7, meta: ['ig_user_id' => '17841400000000001']),
]);

$result->accounts;                          // Collection<AccountMetrics>
$a = $result->accountFor('tiktok', $openId);
$a->followers; $a->following; $a->posts; $a->views;
```

### How identifiers work: `accountId` first, `meta` for the rest

Every driver is handed the same three things: the platform, one **native id**, and a
**token**. `accountId` is that native id — you rarely need `meta` at all.

- **Post metrics.** The post's `nativeId` carries the id; `accountId` is only a label
  (it groups refs and selects the token). It can be anything.
- **Account metrics.** There is no separate id, so the driver uses `accountId` itself
  as the platform-native account id (IG user id, YouTube channel id, LinkedIn URN, …).
  Pass the real native id as `accountId` and you need nothing else.

`meta` is the escape hatch for what does not fit that shape:

1. **A second signal that isn't an id.** Some drivers need more than an id — e.g.
   facebook `facebook_content` to force `post`/`reel` classification. (LinkedIn's
   `is_person` used to live here; the URN now implies it — see the LinkedIn note.)
2. **An id override / alias.** If your `accountId` is a DB key rather than the platform
   id, supply the real id under the platform's alias key; it wins over `accountId`.
   This is what lets `accountId: 1` coexist with the real native id.

Resolution order for the account id is `meta['<alias>'] ?? accountId ?? config`, so
existing `meta` and config setups keep working unchanged.

| Platform  | id alias key         | extra meta         | notes |
|-----------|----------------------|--------------------|-------|
| instagram | `ig_user_id`         | —                  | account metrics only; also falls back to `drivers.instagram.user_id` |
| facebook  | `page_id`            | `facebook_content` | Post nativeId is the `{pageId}_{postId}` composite; reels are the bare video id. Routing is by underscore; force it with `facebook_content` = `post`/`reel` |
| threads   | `threads_user_id`    | —                  | account metrics only; also falls back to `drivers.threads.user_id` |
| youtube   | `channel_id`         | —                  | key-based; also falls back to `drivers.youtube.channel_id` |
| linkedin  | (pass the URN as `accountId`) | `is_person`, `organization_urn` | type is read from the URN; see LinkedIn note below |
| tiktok    | —                    | —                  | open_id comes back from the API |

**Bottom line:** pass the native id as `accountId` and skip `meta` entirely — reach for
`meta` only when a driver needs a second signal, or when your `accountId` isn't the
native id.

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
- **LinkedIn** reads person vs entity straight from the URN you pass as `accountId`:
  `urn:li:person:…` uses `memberFollowersCount?q=me` (the token owner); any other typed
  entity (`urn:li:organization:…`, `urn:li:school:…`, brand) is treated as an
  organization and uses `networkSizes` on that URN. Only when you pass a non-`urn:li:`
  identifier does it fall back to `meta['is_person']`, then to whether an org URN
  resolves (from `meta['organization_urn']` or config). Note: `networkSizes` currently
  uses the `COMPANY_FOLLOWED_BY_MEMBER` edge, so follower counts for non-company
  entities (e.g. schools) may need a different edge — classification is correct, but
  verify that fetch.
- **Facebook** routes by id shape: a `{pageId}_{postId}` composite goes to /insights (reactions summed into likes, post_impressions_unique as reach); a bare reel id goes to /video_insights (plays as views, reaction map summed into likes). Comments and shares are not exposed as discrete counts on either endpoint, so they stay null; reels keep plays, replays, watch time and social_actions in raw.
- **TikTok** cannot query by id, so it pages the account listing and filters. Raise
  `drivers.tiktok.max_videos` if you request ids older than the window.
