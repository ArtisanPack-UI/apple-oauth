---
title: AppleOAuth
---

# `AppleOAuth`

`ArtisanPackUI\AppleOAuth\AppleOAuth` is the aggregator class the facade points at. It holds references to the manager singletons and exposes them via accessor methods.

## Constructor

```php
public function __construct(
    protected OAuthManager $oauth,
    protected ClientSecretGenerator $clientSecret,
    protected TokenManager $tokens,
    protected ScopeRegistry $scopes,
    protected AppleOAuthManager $manager,
)
```

Instantiated once by the service provider; you don't build these directly.

## Methods

### `oauth(): OAuthManager`

The OAuth authorization-code flow manager. See [`OAuthManager`](API-Reference/OAuth-Manager).

```php
$url = AppleOAuth::oauth()->authorizationUrl( $userId );

$response = AppleOAuth::oauth()->handleCallback( $code, $state, $userPayload );
```

### `manager(): AppleOAuthManager`

The consumer-facing manager downstream Apple API clients build on. See [`AppleOAuthManager`](API-Reference/Apple-OAuth-Manager).

```php
$http = AppleOAuth::manager()->request( $connection );
```

### `clientSecret(): ClientSecretGenerator`

The ES256 `client_secret` JWT signer. See [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator).

```php
$jwt = AppleOAuth::clientSecret()->generate();
AppleOAuth::clientSecret()->forget();
```

### `tokens(): TokenManager`

The token persistence + refresh manager. See [`TokenManager`](API-Reference/Token-Manager).

```php
$connection = AppleOAuth::tokens()->store( $response );
$token      = AppleOAuth::tokens()->getValidAccessToken( $connection );
```

### `scopes(): ScopeRegistry`

The scope registry. See [`ScopeRegistry`](API-Reference/Scope-Registry).

```php
// Apple only exposes `name` + `email` today. `register()` is a
// forward-compatible seam — registering a scope Apple doesn't recognize
// will break the authorization request. See the Scopes page for details.
// AppleOAuth::scopes()->register( '<future-apple-scope>' );
AppleOAuth::scopes()->all();
```

## The facade

`ArtisanPackUI\AppleOAuth\Facades\AppleOAuth` extends `Illuminate\Support\Facades\Facade` and returns `'apple-oauth'` from `getFacadeAccessor()`. That resolves to a singleton binding of `AppleOAuth`. Every facade call goes through this instance.

The service provider registers a class alias so you can use the short form:

```php
use AppleOAuth;

AppleOAuth::oauth()->authorizationUrl( $userId );
```

Or the fully-qualified `\ArtisanPackUI\AppleOAuth\Facades\AppleOAuth`.

## The helper

```php
function apple_oauth(): AppleOAuth
{
    return app( 'apple-oauth' );
}
```

Same singleton as the facade. Use whichever style your codebase prefers.

## No `config()` accessor

Unlike the sibling Google package, there is no `AppleOAuth::config()` facade accessor. Resolve the credential driver directly:

```php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

$driver = app( ConfigurationRepository::class );
$driver->save( [ /* ... */ ] );
```

See [Credential Drivers](Drivers) and [`ConfigurationRepository`](API-Reference/Configuration-Repository).
