---
title: AppleOAuthManager
---

# `AppleOAuthManager`

`ArtisanPackUI\AppleOAuth\AppleOAuthManager` is the consumer-facing manager downstream Apple API clients build on top of. It wraps the token-refresh + HTTP-request wiring a caller would otherwise reassemble by hand: ask a [`TokenProvider`](API-Reference/Token-Provider) for a currently-valid bearer token, put it on a Laravel `PendingRequest`, hand the request back.

Downstream packages (calendar, mail, …) type-hint this class or the underlying `TokenProvider` contract — they never touch the OAuth internals.

## Constructor

```php
public function __construct(
    protected TokenProvider $tokens,
    protected HttpFactory $http,
)
```

Bound as a singleton by the service provider. The `TokenProvider` binding defaults to `OAuthTokenProvider` (which delegates to [`TokenManager::getValidAccessToken()`](API-Reference/Token-Manager)).

## Methods

### `request( AppleConnection $connection ): PendingRequest`

Build a Laravel HTTP client pre-authorized with the connection's current access token.

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

$response = AppleOAuth::manager()
    ->request( $connection )
    ->acceptJson()
    ->get( 'https://caldav.icloud.com/...' );
```

The token is resolved through `TokenProvider::accessTokenFor()`, which refreshes transparently when the stored token has expired, so the returned request always carries a token safe to use immediately.

**Throws** `ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException` when the connection cannot yield a usable access token (disconnected, missing refresh token, refresh failure). The exception propagates out of `request()` rather than the eventual HTTP call, giving callers a single point to catch a dead OAuth grant.

### `tokens(): TokenProvider`

Access the underlying token provider directly — handed out for consumers that need only the token string:

```php
$token = AppleOAuth::manager()->tokens()->accessTokenFor( $connection );
```

Useful when passing the token to a third-party SDK that owns its own HTTP client.

## When to type-hint what

Type-hint | Use for
--- | ---
[`TokenProvider`](API-Reference/Token-Provider) (contract) | The stable seam for a downstream service package. Tests can rebind it to a fake without booting the OAuth machinery.
[`AppleOAuthManager`](API-Reference/Apple-OAuth-Manager) (concrete) | When you need both the token and a pre-configured `PendingRequest`. Slightly less test-friendly since you also swap the HTTP factory.
[`TokenManager`](API-Reference/Token-Manager) (concrete) | Only in code that persists callbacks (`store()`) or forces refreshes (`refresh()`). Downstream API calls should not reach for this.

## Example: downstream service package

```php
namespace ArtisanPackUI\Bookings\Calendar\Apple;

use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use Illuminate\Http\Client\Factory as HttpFactory;

class AppleCalendarClient
{
    public function __construct(
        protected TokenProvider $apple,
        protected HttpFactory $http,
    ) {}

    public function listCalendars( AppleConnection $connection ): array
    {
        $token = $this->apple->accessTokenFor( $connection );

        return $this->http
            ->withToken( $token )
            ->acceptJson()
            ->get( 'https://caldav.icloud.com/...' )
            ->json();
    }
}
```

The test suite for `AppleCalendarClient` rebinds `TokenProvider` to a hand-rolled fake that returns `'test-token'`; no HTTP fakes for the token endpoint needed.
