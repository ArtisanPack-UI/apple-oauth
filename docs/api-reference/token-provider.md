---
title: TokenProvider
---

# `TokenProvider`

`ArtisanPackUI\AppleOAuth\Contracts\TokenProvider` is the stable seam other ArtisanPack packages (a calendar client, a mail client) resolve out of the container to authorize their Apple API calls.

Binding the contract — rather than referencing [`AppleOAuth`](API-Reference/Apple-OAuth) or [`TokenManager`](API-Reference/Token-Manager) directly — lets consumers depend on a small surface here, and lets tests swap in a fake provider without booting the OAuth machinery.

## Interface

```php
interface TokenProvider
{
    public function accessTokenFor( AppleConnection $connection ): string;
}
```

That's the whole surface. One method, one return value.

## `accessTokenFor()`

Return a currently-valid OAuth access token for the connection.

The implementation must refresh transparently when the stored access token has expired; a returned token is safe to use as an `Authorization: Bearer <token>` header value against Apple's APIs.

Throws `ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException` when the connection is disconnected or the grant cannot be refreshed.

## Default binding

The service provider binds `TokenProvider::class` to `OAuthTokenProvider` as a singleton:

```php
$this->app->singleton( TokenProvider::class, function ( $app ) {
    return new OAuthTokenProvider( $app->make( TokenManager::class ) );
} );
```

`OAuthTokenProvider` is a two-line class:

```php
class OAuthTokenProvider implements TokenProvider
{
    public function __construct( protected TokenManager $tokens ) {}

    public function accessTokenFor( AppleConnection $connection ): string
    {
        return $this->tokens->getValidAccessToken( $connection );
    }
}
```

## Using it in a downstream package

Type-hint the contract:

```php
use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

class AppleMailClient
{
    public function __construct( protected TokenProvider $apple ) {}

    public function send( AppleConnection $connection, string $body ): void
    {
        $token = $this->apple->accessTokenFor( $connection );

        // ... use $token in an Authorization: Bearer header
    }
}
```

## Rebinding for tests

```php
$this->app->instance( TokenProvider::class, new class implements TokenProvider {
    public function accessTokenFor( AppleConnection $connection ): string
    {
        return 'test-access-token';
    }
} );
```

The fake never touches HTTP, the credential driver, or the client-secret generator, so tests can focus on the downstream package's own behavior. More testing patterns: [Testing](Testing).

## When to use `TokenProvider` vs. `AppleOAuthManager`

- **`TokenProvider`** — when you only need the token string. Cleanest for testing.
- **[`AppleOAuthManager`](API-Reference/Apple-OAuth-Manager)** — when you want a Laravel `PendingRequest` with the token pre-applied. Convenient for HTTP calls; both dependencies (`TokenProvider` + `HttpFactory`) are swappable at the container level.
