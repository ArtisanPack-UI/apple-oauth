---
title: Callback
---

# Callback

The "redeem the code, verify identity claims, hand back a `TokenResponse`" leg. Apple `form_post`s the response to a callback route you own; you delegate to [`OAuthManager::handleCallback()`](API-Reference/OAuth-Manager#handlecallback).

## Route setup

Apple sends the response as a `form_post`. Because that's a real form submission from Apple's origin — not a request from your own site — you exempt the callback path from Laravel's CSRF middleware; the anti-forgery guarantee comes from the `state` parameter, not Laravel's CSRF token.

**Laravel 11+** exempts paths in `bootstrap/app.php`:

```php
->withMiddleware( function ( Middleware $middleware ) {
    $middleware->validateCsrfTokens( except: [
        'apple/callback',
    ] );
} )
```

**Laravel 10** exempts paths on `App\Http\Middleware\VerifyCsrfToken` via its `$except` array.

The route itself:

```php
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;

Route::post( '/apple/callback', function ( Request $request ) {
    try {
        $response = AppleOAuth::oauth()->handleCallback(
            code:          $request->input( 'code', '' ),
            returnedState: $request->input( 'state', '' ),
            userPayload:   $request->input( 'user' ),
        );
    } catch ( OAuthException $e ) {
        return redirect( '/' )->withErrors( [ 'apple' => $e->getMessage() ] );
    }

    $connection = AppleOAuth::tokens()->store( $response );

    return redirect( '/account' )->with( 'status', 'apple.connected' );
} )->name( 'apple.callback' );
```

## The signature

```php
public function handleCallback(
    string $code,
    string $returnedState,
    ?string $userPayload = null,
): TokenResponse
```

- `$code` — the authorization code from Apple's response body.
- `$returnedState` — the `state` Apple echoed back. Compared to the session-stored value with `hash_equals()`.
- `$userPayload` — the raw JSON `user` field from the form POST. Present only on the first authorization; pass `null` on subsequent callbacks.

Returns a [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse). Throws [`OAuthException`](API-Reference/Exceptions#oauthexception) on any failure.

## What it does, step by step

1. **Consumes session context.** Pulls `apple-oauth.state`, `apple-oauth.nonce`, and `apple-oauth.user_id` from the session — via `session->pull()`, so they're removed on read. Missing or empty `state` → `OAuthException("OAuth state mismatch; possible CSRF attempt.")`. Missing `user_id` → `OAuthException("OAuth session missing user context.")`.
2. **Timing-safe state compare.** `hash_equals()` between the stored and returned `state`. Any mismatch → same "OAuth state mismatch" exception.
3. **Loads credentials.** Reads `client_id`, `redirect_uri`, and optional `client_secret` from the active [credential driver](Drivers). Missing `client_id` or `redirect_uri` → `OAuthException("Apple OAuth credentials are not configured.")`. If no `client_secret` is on file, mints one via [`ClientSecretGenerator::generate()`](Client-Secret).
4. **Refuses cleartext.** If `apple-oauth.endpoints.token` isn't `https://`, throws `OAuthException("Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.")` — the `client_secret` is a signed JWT and must not go over the wire in plaintext HTTP.
5. **POSTs to `/auth/token`.** Form body: `grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`, `client_secret`. Non-2xx response → `OAuthException("Apple code exchange failed: <error>")` where `<error>` is Apple's `error` field, or `"exchange_failed"` if the body wasn't parseable.
6. **Extracts tokens.** Reads `access_token`, `id_token`, `refresh_token`, `token_type`, `expires_in` from the response. Missing `access_token` → `OAuthException("Apple token response is missing access_token.")`. Missing `id_token` → similar.
7. **Decodes id_token claims.** Base64url-decodes the middle segment. Malformed JWT (not three segments, non-base64 payload, non-object JSON) → `OAuthException("Apple id_token is malformed.")` (or the more specific variant).
8. **Validates id_token claims.** Enforces:
   - `iss === 'https://appleid.apple.com'` — else `"Apple id_token issuer mismatch: <iss>"`.
   - `aud` contains the configured `client_id` (guards against scalar or array shape) — else `"Apple id_token audience mismatch."`.
   - `exp > time()` — else `"Apple id_token is expired."`.
   - `nonce` matches the session-stored nonce, `hash_equals()` — else `"Apple id_token nonce mismatch."`.
   - `sub` is a non-empty string — else `"Apple id_token is missing sub claim."`.

   **Signature is not verified.** Identity trust rests on the TLS-terminated server-to-server exchange plus these claim checks; if your use case authorizes off the id_token, verify the signature externally with `firebase/php-jwt` before trusting the claims.
9. **Builds the profile.** Merges the id_token's `sub` and `email` with the one-shot `user` payload's `firstName` / `lastName`. The id_token email is canonical — the `user` payload's `email` (if present) is ignored, because the payload rides through the user's browser rather than a server-to-server channel.
10. **Returns a `TokenResponse`.** Carries `userId`, `accessToken`, `refreshToken` (may be null on re-authorizations), `idToken`, `tokenType`, `expiresAt` (`Carbon::now()->addSeconds($expiresIn)` when present, else `null`), and `profile`.

## Persisting the response

```php
$connection = AppleOAuth::tokens()->store( $response );
```

[`TokenManager::store()`](Tokens) writes the `TokenResponse` to an encrypted `apple_connections` row keyed by `user_id`. Details there.

## The one-shot `user` payload

Apple sends the `user` field **only on the first authorization** for a given Services ID. It's a JSON string like:

```json
{
    "name": { "firstName": "Ada", "lastName": "Lovelace" },
    "email": "ada@example.com"
}
```

`buildProfile()` extracts `firstName` and `lastName` from `name`. It **discards** the `email` from the `user` payload — the id_token's `email` claim is the canonical source since it comes from the server-to-server exchange rather than the browser-mediated form POST.

On the second and subsequent authorizations, Apple omits the `user` field entirely. `handleCallback()` receives `null` for `$userPayload` and the returned `AppleUserProfile` will have `firstName === null` and `lastName === null`. Persist the name on the first callback — [`TokenManager::store()`](Tokens) preserves the existing `email` and `apple_user_id` on updates, so you don't lose them if the second-round profile arrives partial.

## Refresh-token preservation

Apple only issues a `refresh_token` on the **initial** authorization for a given grant; re-authorizations without a new consent do not re-emit one. [`TokenManager::store()`](Tokens) handles this correctly — it only overwrites the stored refresh token when the incoming `TokenResponse` carries a non-empty one.

## Failure-mode summary

| Exception message | Cause |
|---|---|
| `OAuth state mismatch; possible CSRF attempt.` | Session missing the stored `state`, or Apple's returned `state` doesn't match. Cookie / SameSite / session-driver misconfig. |
| `OAuth session missing user context.` | Session missing `apple-oauth.user_id` — usually the callback fired without a prior `/connect`. |
| `Apple OAuth credentials are not configured.` | Empty `client_id` or `redirect_uri` on the active [credential driver](Drivers). |
| `Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.` | `apple-oauth.endpoints.token` was overridden to `http://`. |
| `Apple code exchange failed: <error>` | Apple's `/auth/token` returned a non-2xx. `<error>` is Apple's error code (e.g. `invalid_client`, `invalid_grant`). |
| `Apple token response is missing access_token.` / `... id_token.` | Apple returned 2xx but omitted the required field. Rare — usually indicates the endpoint override is misconfigured. |
| `Apple id_token is malformed.` / `... is not valid base64.` / `... is not a JSON object.` | JWT decoding failed. Rare with a real Apple response; common when tests hand-craft a bogus id_token. |
| `Apple id_token issuer mismatch: <iss>` | The `iss` claim isn't `https://appleid.apple.com`. Usually a test double misconfiguration. |
| `Apple id_token audience mismatch.` | The `aud` claim doesn't include your configured `client_id`. |
| `Apple id_token is expired.` | Clock skew between your server and Apple; also can indicate an id_token replayed from a stale flow. |
| `Apple id_token nonce mismatch.` | Session-stored `nonce` doesn't match the id_token's `nonce` claim. Same session-cookie causes as state mismatch. |
| `Apple id_token is missing sub claim.` | Would only happen if Apple returned a genuinely malformed id_token — never seen in practice. |
