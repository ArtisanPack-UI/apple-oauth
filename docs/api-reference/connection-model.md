---
title: AppleConnection
---

# `AppleConnection`

`ArtisanPackUI\AppleOAuth\Models\AppleConnection` — the Eloquent model representing a connected Apple account for a user. Prose walkthrough: [Connection Model](Connection-Model). This page is the terse reference.

## Table

`apple_connections`. Unique on `user_id`. Full schema: [Connection Model → Table](Connection-Model#table-apple_connections).

## Constants

| Constant | Value |
|---|---|
| `STATUS_CONNECTED` | `'connected'` |
| `STATUS_DISCONNECTED` | `'disconnected'` |

## Properties

| Property | Type | Cast |
|---|---|---|
| `id` | `int` | — |
| `user_id` | `int` | — |
| `apple_user_id` | `?string` | — |
| `email` | `?string` | — |
| `access_token` | `?string` | `encrypted` |
| `refresh_token` | `?string` | `encrypted` |
| `id_token` | `?string` | `encrypted` |
| `token_type` | `string` | — |
| `scopes` | `?array<int, string>` | `array` |
| `expires_at` | `?Carbon` | `datetime` |
| `status` | `string` | — |
| `disconnect_reason` | `?string` | — |
| `created_at` / `updated_at` | `Carbon` | — |

## `$fillable`

```php
[
    'user_id',
    'apple_user_id',
    'email',
    'access_token',
    'refresh_token',
    'id_token',
    'token_type',
    'scopes',
    'expires_at',
    'status',
    'disconnect_reason',
]
```

## Methods

### `user(): BelongsTo`

Relationship to the user model named by `config('apple-oauth.user_model')` (default `App\Models\User`). Foreign key: `user_id`.

### `isExpired(): bool`

`true` when `expires_at` is null or within 60 seconds of now. See [Connection Model → isExpired()](Connection-Model#isexpired-bool).

### `isConnected(): bool`

`true` when `status === STATUS_CONNECTED`.

### `grantedScopes(): array`

Normalizes the `scopes` cast array to `list<string>`. Returns `[]` when `scopes` is null or non-array.

### `markDisconnected( ?string $reason = null ): void`

Sets `status = STATUS_DISCONNECTED`, `disconnect_reason = $reason`, and saves. Called by [`TokenManager::refresh()`](API-Reference/Token-Manager) automatically on `invalid_grant`.

## Casts method

Casts are declared via the `casts()` method (not the `$casts` property) — matches the Laravel 11+ convention:

```php
protected function casts(): array
{
    return [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'id_token'      => 'encrypted',
        'scopes'        => 'array',
        'expires_at'    => 'datetime',
    ];
}
```

## `AppleUserProfile`

`ArtisanPackUI\AppleOAuth\OAuth\AppleUserProfile` — immutable identity value object; not persisted as a model. See [OAuth Manager → AppleUserProfile](API-Reference/OAuth-Manager#appleuserprofile).

## Common queries

```php
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

// Load a user's connection (may be null)
$connection = AppleConnection::firstWhere( 'user_id', $userId );

// Only connected ones
$connections = AppleConnection::where( 'status', AppleConnection::STATUS_CONNECTED )->get();

// Via the belongsTo (if you added a hasOne on User)
$connection = $user->appleConnection;
```
