---
title: API Reference
---

# API Reference

The public surface of `artisanpack-ui/apple-oauth`.

Sub-pages by class:

- [`AppleOAuth` — the facade / helper root](API-Reference/Apple-OAuth)
- [`AppleOAuthManager` — authorized HTTP requests + `TokenProvider` seam](API-Reference/Apple-OAuth-Manager)
- [`OAuthManager` — authorization-code flow](API-Reference/OAuth-Manager)
- [`TokenManager` — persistence and refresh](API-Reference/Token-Manager)
- [`ClientSecretGenerator` — ES256 client-secret JWT signer](API-Reference/Client-Secret-Generator)
- [`ScopeRegistry`](API-Reference/Scope-Registry)
- [`TokenProvider` — the downstream seam contract](API-Reference/Token-Provider)
- [`ConfigurationRepository` — the credential-driver contract](API-Reference/Configuration-Repository)
- [`AppleConnection` — the Eloquent connection model](API-Reference/Connection-Model)
- [Exceptions](API-Reference/Exceptions)

## The facade

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

AppleOAuth::oauth();         // OAuthManager
AppleOAuth::manager();       // AppleOAuthManager
AppleOAuth::clientSecret();  // ClientSecretGenerator
AppleOAuth::tokens();        // TokenManager
AppleOAuth::scopes();        // ScopeRegistry
```

Every accessor returns a singleton — the same instance is shared across the request lifecycle. The `AppleOAuth` class itself just aggregates the managers so callers have a single entry point.

## The helper

```php
apple_oauth();   // same as app( 'apple-oauth' )
```

Returns the `ArtisanPackUI\AppleOAuth\AppleOAuth` instance. Equivalent to using the facade — pick whichever style matches your codebase.

## Container bindings

The service provider registers:

| Abstract | Concrete | Scope |
|---|---|---|
| `apple-oauth` | `ArtisanPackUI\AppleOAuth\AppleOAuth` | Singleton |
| `ConfigurationRepository::class` | `ConfigDriver` / `DatabaseDriver` / `CmsSettingsDriver` (per `apple-oauth.driver`) | Bind (re-resolved per lookup) |
| `ConfigDriver::class` | `ConfigDriver` | Singleton |
| `DatabaseDriver::class` | `DatabaseDriver` | Singleton |
| `CmsSettingsDriver::class` | `CmsSettingsDriver` | Singleton |
| `ClientSecretGenerator::class` | `ClientSecretGenerator` | Singleton |
| `ScopeRegistry::class` | `ScopeRegistry` | Singleton |
| `OAuthManager::class` | `OAuthManager` | Singleton |
| `TokenManager::class` | `TokenManager` | Singleton |
| `TokenProvider::class` | `OAuthTokenProvider` | Singleton |
| `AppleOAuthManager::class` | `AppleOAuthManager` | Singleton |

Rebind [`ConfigurationRepository`](API-Reference/Configuration-Repository) to swap in a custom credential driver. Rebind [`TokenProvider`](API-Reference/Token-Provider) to swap in a fake for tests. The `AppleOAuth` aggregator is itself a singleton that stores its five manager instances at construction time — accessors return those stored references and do not re-resolve the container. Rebind `ConfigurationRepository` and `TokenProvider` **before the first resolve** of any singleton that consumes them (in a service provider's `register()`, or in test setup before touching the facade). Once a downstream manager has been constructed with the default binding, changing the binding has no effect on the already-cached instance.

## Namespace map

```
ArtisanPackUI\AppleOAuth\
├── AppleOAuth.php                      — the aggregator
├── AppleOAuthManager.php               — authorized HTTP requests
├── AppleOAuthServiceProvider.php
├── helpers.php                         — apple_oauth() function
├── Facades\
│   └── AppleOAuth.php                  — facade
├── Configuration\
│   ├── ConfigDriver.php
│   ├── DatabaseDriver.php
│   └── CmsSettingsDriver.php
├── Contracts\
│   ├── ConfigurationRepository.php
│   └── TokenProvider.php
├── OAuth\
│   ├── OAuthManager.php
│   ├── ClientSecretGenerator.php
│   ├── TokenResponse.php
│   └── AppleUserProfile.php
├── Scopes\
│   └── ScopeRegistry.php
├── Tokens\
│   ├── TokenManager.php
│   └── OAuthTokenProvider.php
├── Models\
│   └── AppleConnection.php
└── Exceptions\
    ├── OAuthException.php
    └── TokenRefreshException.php
```

## No public routes

The package does not ship any web routes. You own the connect and callback routes for your app. See [OAuth Flow](Oauth) for wiring.

## Publish tags

| Tag | What it publishes |
|---|---|
| `apple-oauth-config` | `config/apple-oauth.php` |
| `apple-oauth-migrations` | Both migrations to `database/migrations/`. |

## Filter hooks

| Hook | Contract | Purpose |
|---|---|---|
| `ap.apple-oauth.scopes` | Filter — receives and returns `array<int, string>` | Contribute scopes to the [registry](Scopes). Fires inside `ScopeRegistry::all()`. |

---
Continue to [Testing](Testing) →
