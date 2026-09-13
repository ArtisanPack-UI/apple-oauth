---
title: Scopes
---

# Scopes

Apple's Sign in with Apple flow uses **scopes** to gate what identity data the authorization returns. `artisanpack-ui/apple-oauth` lets any installed service package contribute scopes and unions them into a single consent screen.

## What Apple actually exposes

Sign in with Apple exposes only two scopes today:

- `name` — releases the user's display name in the one-shot `user` payload on the first authorization.
- `email` — releases the user's email address on the id_token's `email` claim.

Apple gates the one-shot `user` payload on requesting **both** `name` and `email`, so the package's baseline requests both. There's no per-service Apple scope for calendar, mail, or anything else — those services authenticate through their own protocols (CalDAV for iCloud calendars, IMAP/SMTP for Mail) using an app-specific password or OAuth-issued access token that this package brokers.

Since Apple's scope surface is fixed, incremental consent doesn't apply — a connected user has both scopes granted, and there's nothing more to ask for.

## The registry

`ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry` collects three sources:

1. **The baseline** — always requested:
   - `name`
   - `email`
2. **Filter-hook contributions** via `ap.apple-oauth.scopes` (from [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks)).
3. **Imperative registrations** via `ScopeRegistry::register( $scope )`.

`ScopeRegistry::all()` returns the de-duplicated, trimmed union. Empty strings are dropped, and duplicates across the three sources are collapsed.

The registry is shaped generically so future Apple-issued scopes (or a bring-your-own baseline in a downstream broker) can be layered in without a signature change.

## Registering scopes from a service package

Preferred: hook the `ap.apple-oauth.scopes` filter in your service package's `boot()` method:

```php
use ArtisanPackUI\Hooks\Facades\Filter;

class ExampleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Filter::add( 'ap.apple-oauth.scopes', function ( array $scopes ): array {
            $scopes[] = 'my.custom.scope';
            return $scopes;
        } );
    }
}
```

The registry calls `Filter::apply( 'ap.apple-oauth.scopes', [] )` inside `all()`, so every hooked callback contributes to the union. Callback order doesn't matter — the union is de-duplicated at the end.

## Registering scopes from application code

For app-level scopes without a service provider:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

AppleOAuth::scopes()->register( 'my.custom.scope' );
```

Register from anywhere that runs before an authorization URL is built — usually inside a service provider's `boot()`.

## Reading the current union

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

$scopes = AppleOAuth::scopes()->all();
// ['name', 'email']
```

## Incremental-consent helpers

The registry exposes `missing()` and `hasAllRequired()` for parity with the sibling Google package. Since Apple's scope surface is fixed to `name` + `email`, these are largely academic — every connected user has both — but they're still useful for tests or hypothetical future scopes:

```php
$granted = $connection->grantedScopes();

AppleOAuth::scopes()->missing( $granted );          // []
AppleOAuth::scopes()->hasAllRequired( $granted );   // true
```

`AppleConnection::grantedScopes()` reads the stored `scopes` JSON column. The column is nullable and defaults to `null` — the OAuth flow doesn't currently populate it, so `grantedScopes()` returns `[]` for a fresh connection until you explicitly set it.

## Overriding scopes for a specific flow

`OAuthManager::authorizationUrl()` accepts an optional scope override:

```php
$url = AppleOAuth::oauth()->authorizationUrl(
    userId:   $user->id,
    override: [ 'email' ],
);
```

Rarely useful in practice — dropping `name` costs you the one-shot display-name payload without buying anything back. Included for completeness / testing.

## The deprecated `config('apple-oauth.scopes')` array

`config/apple-oauth.php` still carries a `'scopes' => [ 'name', 'email' ]` entry for backward compatibility with pre-1.0 publishes. It is **not read at runtime** — `OAuthManager::authorizationUrl()` reads from the registry, not the config array. Set custom scopes via the filter hook (or the `$override` argument).
