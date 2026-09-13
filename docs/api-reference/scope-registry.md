---
title: ScopeRegistry
---

# `ScopeRegistry`

`ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry` collects the union of OAuth scopes required by dependent packages. Prose walkthrough: [Scopes](Scopes). This page is the terse method reference.

## Baseline

The registry hard-codes a two-scope baseline:

```php
protected array $baseline = [
    'name',
    'email',
];
```

Apple gates the one-shot `user` payload on requesting both — requesting only one loses the display name.

## Methods

### `register( string $scope ): void`

Imperatively register a scope from application code. Preferred over the filter hook for apps that don't have a service provider dedicated to the integration.

- Trims the input; empty strings are dropped silently.
- De-duplicates against previously-registered imperative scopes.

Prefer the `ap.apple-oauth.scopes` filter hook for package-supplied scopes.

### `all(): array`

Get the full de-duplicated union of registered scopes, as a `list<string>`.

Union sources, in order:

1. The `baseline` (`name`, `email`).
2. Imperative registrations via `register()`.
3. Filter-hook contributions via `Filter::apply( 'ap.apple-oauth.scopes', [] )`.

Post-processing: strval, trim, drop empties, `array_unique`, re-index.

### `missing( array $grantedScopes ): array`

Compute the set of required scopes not yet granted by the user.

```php
$granted = $connection->grantedScopes();
$missing = AppleOAuth::scopes()->missing( $granted );
```

Returns a `list<string>` of scopes in `all()` that aren't in `$grantedScopes`. Apple's scope surface is fixed today, so this is largely academic — every connected user has both baseline scopes granted.

### `hasAllRequired( array $grantedScopes ): bool`

Whether every required scope has already been granted.

```php
if ( AppleOAuth::scopes()->hasAllRequired( $connection->grantedScopes() ) ) {
    // full grant
}
```

Equivalent to `[] === $this->missing( $grantedScopes )`.

## Filter hook

`ap.apple-oauth.scopes` — invoked inside `all()` with an initial `[]`. Every registered callback receives the array-in-progress and returns it (possibly modified):

```php
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.apple-oauth.scopes', function ( array $scopes ): array {
    $scopes[] = 'my.custom.scope';
    return $scopes;
} );
```

Callback order doesn't matter — the union is de-duplicated at the end.

## Container binding

Bound as a singleton by the service provider. The same registry instance is shared across the request, so imperative `register()` calls persist for the lifetime of the request.
