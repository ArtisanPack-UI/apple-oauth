---
title: Tokens
---

# Tokens

`ArtisanPackUI\AppleOAuth\Tokens\TokenManager` owns token persistence and refresh. Downstream service packages don't call it directly — they resolve tokens through the [`TokenProvider`](API-Reference/Token-Provider) seam — but you use `TokenManager` when persisting a fresh `TokenResponse` after a callback and when you need to force a refresh in an edge case.

## Persisting the callback's `TokenResponse`

After [`OAuthManager::handleCallback()`](Oauth/Callback) returns a [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse), persist it:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

$connection = AppleOAuth::tokens()->store( $response );
```

`store()`:

1. `AppleConnection::firstOrNew( [ 'user_id' => $response->userId ] )` — creates a fresh row for first authorization, or loads the existing row for a reconnect.
2. Assigns `apple_user_id` (from the id_token's validated `sub`), `access_token`, `id_token`, `token_type`, `expires_at`, `status = 'connected'`, and clears `disconnect_reason`.
3. Preserves the existing `email` when the incoming `profile->email` is null (Apple omits it on re-authorizations after the first).
4. Preserves the existing `refresh_token` when the incoming `refreshToken` is null or empty (Apple only emits refresh tokens on the initial authorization for a given grant).
5. Saves.

Returns the persisted `AppleConnection`. Full column reference: [Connection Model](Connection-Model).

## Getting a valid access token

Downstream code should type-hint the [`TokenProvider`](API-Reference/Token-Provider) contract:

```php
use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

public function __construct( protected TokenProvider $apple ) {}

public function fetch( AppleConnection $connection ): array
{
    $token = $this->apple->accessTokenFor( $connection );
    // ...
}
```

The default binding of `TokenProvider` is `OAuthTokenProvider`, which delegates to `TokenManager::getValidAccessToken()`. If you want to reach the manager directly:

```php
$token = AppleOAuth::tokens()->getValidAccessToken( $connection );
```

`getValidAccessToken()` decides what to return:

1. If the connection isn't connected → throws `TokenRefreshException("Apple connection is disconnected.")`.
2. If the current access token is present and not close to expiring → returns it as-is.
3. Otherwise → calls `refresh()` and returns the fresh token.

## Refresh window

`AppleConnection::isExpired()` treats the token as expired **60 seconds before** `expires_at`:

```php
public function isExpired(): bool
{
    if ( null === $this->expires_at ) {
        return true;
    }

    return $this->expires_at->copy()->subSeconds( 60 )->isPast();
}
```

So the manager refreshes proactively — no API call ever ships with a token about to die mid-request.

Missing `expires_at` (which shouldn't happen but Apple's response is technically allowed to omit `expires_in`) is treated as expired: the next call triggers a refresh.

## Forced refresh

If you know for some reason the stored token is bad, force a refresh:

```php
AppleOAuth::tokens()->refresh( $connection );
```

Bypasses the expiry check and always hits `/auth/token`. Useful in tests or when reacting to a `401` from Apple that the expiry check didn't predict.

## What happens during refresh

`TokenManager::refresh()`:

1. If no refresh token is on file → `$connection->markDisconnected( 'Missing refresh token.' )` and throws `TokenRefreshException("No refresh token stored for this connection.")`.
2. Reads `client_id` from the [credential driver](Drivers). Missing → `TokenRefreshException("Apple OAuth credentials are not configured.")`.
3. Reads or mints `client_secret` — the pre-minted static value if configured, else [`ClientSecretGenerator::generate()`](Client-Secret) mints a fresh ES256 JWT.
4. Refuses cleartext. If `apple-oauth.endpoints.token` isn't `https://` → `TokenRefreshException("Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.")`.
5. POSTs to `/auth/token`:
   ```
   client_id      = <from config driver>
   client_secret  = <static or minted JWT>
   refresh_token  = <from connection>
   grant_type     = refresh_token
   ```
6. On non-success:
   - If Apple returned `error=invalid_grant` → `$connection->markDisconnected( 'Refresh token revoked or expired.' )`.
   - Either way, throws `TokenRefreshException("Apple token refresh failed: <error>")` where `<error>` is Apple's `error` field, or `"refresh_failed"` if the body wasn't parseable.
7. On success:
   - Missing `access_token` → `TokenRefreshException("Apple token refresh response is missing access_token.")`.
   - Sets `access_token`, `token_type` (if present), `expires_at` (`Carbon::now()->addSeconds($expiresIn)`, defaulting to 3600 if Apple omits `expires_in` — never leaves it in the past).
   - Persists a rotated `refresh_token` if one is returned. Apple typically doesn't rotate, but the OAuth2 spec permits it; the persistence here defends against a silent break if Apple ever starts.
8. Saves and returns the fresh access token.

Every path either returns a fresh access token or throws — there's no partial-success state.

## Failure modes

### `Apple connection is disconnected.`

The connection's `status` is `'disconnected'` — someone (a prior refresh failure, a user click, an admin) marked it dead. Show a "Reconnect Apple" prompt to the user; a disconnected connection stays disconnected until they re-run `/apple/connect`.

### `No refresh token stored for this connection.`

Rare, but possible if:

- The initial authorization somehow didn't return a `refresh_token`. Apple normally does on the first consent; if it didn't, something else was already broken.
- The `refresh_token` column was wiped (manual DB edit, or an `APP_KEY` rotation that killed the encrypted cast).

The manager marks the connection disconnected so subsequent calls fail loudly.

### `Apple token refresh failed: invalid_grant`

The refresh token has been revoked or expired. Common causes:

- User revoked the Sign in with Apple grant at [appleid.apple.com](https://appleid.apple.com/) under "Sign in with Apple".
- Refresh token expired — Apple doesn't publish exact TTLs, but grants can eventually age out.
- User's Apple ID password changed in some configurations.

The manager marks the connection disconnected with reason `"Refresh token revoked or expired."`.

### `Apple token refresh failed: <other error>`

Any other error (`invalid_client`, `invalid_request`, etc.) — usually a misconfiguration. The connection is **not** marked disconnected in this case; the caller can retry after fixing config. Only `invalid_grant` triggers auto-disconnect.

## Handling exceptions in service packages

```php
use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;

try {
    $token = $this->apple->accessTokenFor( $connection );
} catch ( TokenRefreshException $e ) {
    $connection->refresh(); // re-fetch; the manager may have flipped status

    if ( ! $connection->isConnected() ) {
        return redirect()->route( 'settings.integrations' )
            ->with( 'error', __( 'Please reconnect Apple.' ) );
    }

    // Otherwise transient — retry once or bubble up.
    throw $e;
}
```

## Concurrency

`TokenManager` doesn't hold locks. If two workers hit `getValidAccessToken()` for the same connection at the exact same time, both will refresh — one will win the DB write and the other will overwrite it with what it received. In practice this is harmless (both tokens are valid; Apple issues them independently). If your workload is genuinely concurrent enough to care, wrap the call in `Cache::lock("apple-refresh:{$connection->id}")` or serialize refreshes through a queue.

## Testing

Fake Apple's token endpoint with `Http::fake()`:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://appleid.apple.com/auth/token' => Http::response( [
        'access_token' => 'new-token',
        'expires_in'   => 3600,
        'token_type'   => 'Bearer',
    ] ),
] );

$connection = AppleConnection::factory()->create( [
    'access_token'  => 'expired',
    'refresh_token' => 'a-refresh-token',
    'expires_at'    => now()->subMinute(),
    'status'        => 'connected',
] );

expect( AppleOAuth::tokens()->getValidAccessToken( $connection ) )->toBe( 'new-token' );
```

The manager resolves its HTTP client from `Illuminate\Http\Client\Factory`, which `Http::fake()` swaps in transparently. More patterns: [Testing](Testing).
