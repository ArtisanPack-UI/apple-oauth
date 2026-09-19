---
title: Configuration
---

# Configuration

Full reference for `config/apple-oauth.php`. Publish the file first if you want to edit it in place:

```bash
php artisan vendor:publish --tag=apple-oauth-config
```

## Options

| Key | Type | Default | Meaning |
|---|---|---|---|
| `driver` | `string` | `env('APPLE_OAUTH_DRIVER', 'config')` | Which [credential driver](Drivers) resolves: `config`, `database`, or `cms`. |
| `client_id` | `?string` | `env('APPLE_OAUTH_CLIENT_ID')` | The Services ID from Apple Developer. |
| `team_id` | `?string` | `env('APPLE_OAUTH_TEAM_ID')` | The 10-character Apple Developer team ID. |
| `key_id` | `?string` | `env('APPLE_OAUTH_KEY_ID')` | The 10-character Key ID of the `.p8` used to sign the client secret. |
| `private_key` | `?string` | `env('APPLE_OAUTH_PRIVATE_KEY')` | Absolute path to the `.p8` file, or inline PEM. |
| `client_secret` | `?string` | `env('APPLE_OAUTH_CLIENT_SECRET')` | Pre-minted JWT string; bypasses the ES256 signer when set. |
| `client_secret_ttl` | `int` | `3600` | Lifetime of minted `client_secret` JWTs, seconds. Clamped to Apple's six-month max. |
| `client_secret_leeway` | `int` | `30` | Seconds subtracted from `client_secret_ttl` when caching, so a warmed JWT is never handed out on the edge of expiry. |
| `redirect_uri` | `?string` | `env('APPLE_OAUTH_REDIRECT_URI')` | Absolute HTTPS URL registered with the Services ID's Return URLs. |
| `scopes` | `array<int,string>` | `[ 'name', 'email' ]` | Historical scope list. Since 1.0.0 the source of truth for requested scopes is [`ScopeRegistry`](API-Reference/Scope-Registry) — see [Scopes](Scopes). |
| `endpoints.authorize` | `string` | `https://appleid.apple.com/auth/authorize` | Authorization endpoint. Overridable for testing. |
| `endpoints.token` | `string` | `https://appleid.apple.com/auth/token` | Token endpoint. Overridable for testing. Must be HTTPS — the code exchange and token refresh refuse cleartext. |
| `user_model` | `class-string` | `env('APPLE_OAUTH_USER_MODEL', 'App\Models\User')` | The user model an `apple_connections` row belongs to. |

## Two credential modes

The `client_id` + `redirect_uri` pair is always required. Beyond that there are two modes:

**Generated (production)** — the ES256 signer mints the `client_secret` JWT on demand. Requires:

- `client_id`
- `team_id`
- `key_id`
- `private_key`
- `redirect_uri`

**Static override (local testing)** — set `client_secret` to a pre-minted JWT string. The signer is bypassed entirely and `team_id`, `key_id`, and `private_key` are not required.

The credential driver's `isConfigured()` returns `true` for either mode. Details: [API Reference → Configuration Repository](API-Reference/Configuration-Repository).

## The `scopes` array is deprecated

`config('apple-oauth.scopes')` is kept for backward compatibility with existing publishes but is not read at runtime. [`OAuthManager::authorizationUrl()`](API-Reference/OAuth-Manager) reads from [`ScopeRegistry`](API-Reference/Scope-Registry), which unions:

1. The baseline `['name', 'email']` (hard-coded on the registry).
2. Contributions from the `ap.apple-oauth.scopes` filter hook.
3. Imperative registrations via `ScopeRegistry::register()`.

Callers who need custom scopes should register them through the filter hook, or pass an explicit `$override` array to `authorizationUrl()`. See [Scopes](Scopes).

## Endpoints override for testing

Point at a mock server in integration tests:

```php
// tests/TestCase.php
protected function defineEnvironment( $app ): void
{
    parent::defineEnvironment( $app );

    $app[ 'config' ]->set( 'apple-oauth.endpoints', [
        'authorize' => 'https://mock.test/auth/authorize',
        'token'     => 'https://mock.test/auth/token',
    ] );
}
```

Both must remain HTTPS — `OAuthManager::handleCallback()` and `TokenManager::refresh()` both check the token endpoint's scheme and throw before sending the request if it isn't `https`.
