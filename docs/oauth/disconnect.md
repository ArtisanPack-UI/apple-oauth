---
title: Disconnect
---

# Disconnect

Disconnect a user's Apple connection so subsequent authorized-request calls fail loudly instead of silently succeeding.

## No built-in route

There's no `POST /apple/disconnect` route in the package. Sign in with Apple doesn't need one — the flow is simpler than Google's (no incremental consent, no per-service scope UX), so how you surface "disconnect from Apple" is app-specific UX.

Wire your own:

```php
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use Illuminate\Http\Request;

Route::post( '/apple/disconnect', function ( Request $request ) {
    $connection = AppleConnection::firstWhere( 'user_id', $request->user()->id );

    if ( $connection ) {
        $connection->markDisconnected( 'Disconnected by user.' );
    }

    return redirect( '/account' )->with( 'status', 'apple.disconnected' );
} )->middleware( 'auth' )->name( 'apple.disconnect' );
```

## `markDisconnected()` behavior

`AppleConnection::markDisconnected( ?string $reason = null )`:

- Sets `status = 'disconnected'` (`AppleConnection::STATUS_DISCONNECTED`).
- Sets `disconnect_reason = $reason` (or `null`).
- Saves the model.

The tokens are **left on the row**. This is deliberate — an admin can inspect the last-known state after a disconnect, and reconnecting doesn't need to re-fetch identity from Apple if the id_token's `sub` is unchanged.

## Effect on API calls

Once disconnected, [`AppleOAuthManager::request()`](API-Reference/Apple-OAuth-Manager) throws `TokenRefreshException("Apple connection is disconnected.")` immediately, without hitting Apple. Same for [`TokenManager::getValidAccessToken()`](Tokens) and any code that resolves through [`TokenProvider::accessTokenFor()`](API-Reference/Token-Provider).

Downstream code should catch this and prompt for reconnect:

```php
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;

try {
    $token = $apple->accessTokenFor( $connection );
} catch ( TokenRefreshException $e ) {
    return redirect()->route( 'settings.integrations' )
        ->with( 'error', __( 'Please reconnect Apple.' ) );
}
```

## Reconnect flow

Point the user back at your `/apple/connect` route. When they authorize again:

- [`OAuthManager::handleCallback()`](Oauth/Callback) returns a fresh `TokenResponse`.
- [`TokenManager::store()`](Tokens) uses `AppleConnection::firstOrNew( [ 'user_id' => ... ] )`, so the existing row is updated in place — same `id`, same relationships.
- `status` flips back to `'connected'`, `disconnect_reason` is cleared, and fresh tokens are stored.

The one-shot `user` payload will not be included on the reconnect (Apple only ever emits it on the first authorization for a Services ID + user pair). `TokenManager::store()` preserves the existing `email` and `apple_user_id` when the incoming profile is partial.

## Automatic disconnection on `invalid_grant`

The package auto-disconnects when Apple rejects a refresh with `error=invalid_grant`. [`TokenManager::refresh()`](Tokens) calls `markDisconnected( 'Refresh token revoked or expired.' )` on that specific error before throwing `TokenRefreshException`. Details: [Tokens](Tokens).

## Local-only revocation

Neither `markDisconnected()` nor the auto-disconnect path calls Apple's revoke endpoint. The refresh token stays valid on Apple's side until:

- The user manually revokes the Sign in with Apple grant at [appleid.apple.com](https://appleid.apple.com/) under "Sign in with Apple".
- Your app hits Apple's revoke endpoint.

If you need remote revocation, POST to `https://appleid.apple.com/auth/revoke` before calling `markDisconnected()`:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Support\Facades\Http;

$clientSecret = AppleOAuth::clientSecret()->generate();

Http::asForm()->post( 'https://appleid.apple.com/auth/revoke', [
    'client_id'       => config( 'apple-oauth.client_id' ),
    'client_secret'   => $clientSecret,
    'token'           => $connection->refresh_token,
    'token_type_hint' => 'refresh_token',
] );

$connection->markDisconnected( 'Revoked at Apple.' );
```

Remote revocation is best-effort — Apple can return 200 even if the token wasn't matched. Always update local state after, regardless of the remote response.
