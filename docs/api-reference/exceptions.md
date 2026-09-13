---
title: Exceptions
---

# Exceptions

`artisanpack-ui/apple-oauth` throws two exception types, both extending `RuntimeException`. Catch by type — code should switch on the class, not on the message string.

> **Do not pass `$e->getMessage()` straight to the browser.** The messages below embed interpolated diagnostic values (`<error>` from Apple's response body, `<iss>` from a mismatched id_token issuer, `<path>` for a missing `.p8` file on the deploy host) that reveal internal state. Log the raw exception for operators (`Log::warning( ..., [ 'exception' => $e ] )`) and render a fixed, translated user-facing message from the exception **class**, not from `$e->getMessage()`. The tables here are the operator-facing reference; treat every message as internal.

## `OAuthException`

`ArtisanPackUI\AppleOAuth\Exceptions\OAuthException`

Thrown by [`OAuthManager`](API-Reference/OAuth-Manager) and [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) when the OAuth negotiation itself fails.

### Thrown by `OAuthManager::authorizationUrl()`

| Message | Cause |
|---|---|
| `Apple OAuth credentials are not configured.` | Empty `client_id` or `redirect_uri` on the active credential driver. |

### Thrown by `OAuthManager::handleCallback()`

| Message | Cause |
|---|---|
| `OAuth state mismatch; possible CSRF attempt.` | Session missing stored `state`, or Apple's returned `state` doesn't match. |
| `OAuth session missing user context.` | Session missing `apple-oauth.user_id`. |
| `Apple OAuth credentials are not configured.` | Empty `client_id` or `redirect_uri` on the credential driver. |
| `Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.` | `apple-oauth.endpoints.token` overridden to `http://`. |
| `Apple code exchange failed: <error>` | Apple's `/auth/token` returned non-2xx. |
| `Apple token response is missing access_token.` | Apple returned 2xx but omitted `access_token`. |
| `Apple token response is missing id_token.` | Apple returned 2xx but omitted `id_token`. |
| `Apple id_token is malformed.` / `... is not valid base64.` / `... is not a JSON object.` | JWT decoding failed. |
| `Apple id_token issuer mismatch: <iss>` | `iss` claim isn't `https://appleid.apple.com`. |
| `Apple id_token audience mismatch.` | `aud` claim doesn't include the configured `client_id`. |
| `Apple id_token is expired.` | `exp` claim is in the past. |
| `Apple id_token nonce mismatch.` | Session `nonce` doesn't match the id_token `nonce`. |
| `Apple id_token is missing sub claim.` | `sub` claim empty or absent. |

### Thrown by `ClientSecretGenerator::generate()`

| Message | Cause |
|---|---|
| `Apple client-secret requires team_id, key_id, and client_id.` | One of the three is empty. |
| `Apple OAuth private_key is not configured.` | `private_key` empty. |
| `Apple OAuth private_key file is not readable: <path>` | Path form points at an unreadable / missing file. |
| `Apple OAuth private_key could not be parsed.` | `openssl_pkey_get_private()` returned false. |
| `Apple OAuth private_key must be a P-256 (prime256v1) EC key; ES256 does not accept other curves.` | Wrong curve or key type. |
| `Failed to sign Apple client-secret JWT.` | `openssl_sign()` returned false. |
| `Malformed ECDSA signature: missing SEQUENCE tag.` / `... missing INTEGER tag.` | DER parsing failure. |

## `TokenRefreshException`

`ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException`

Thrown by [`TokenManager`](API-Reference/Token-Manager) and every [`TokenProvider`](API-Reference/Token-Provider) implementation when a valid access token can't be produced.

| Message | Cause | Side effect |
|---|---|---|
| `Apple connection is disconnected.` | Connection `status` is `disconnected`. | None — the connection stays disconnected. |
| `No refresh token stored for this connection.` | `refresh_token` column empty. | Connection `markDisconnected( 'Missing refresh token.' )`. |
| `Apple OAuth credentials are not configured.` | `client_id` empty on the credential driver. | None. |
| `Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.` | `apple-oauth.endpoints.token` overridden to `http://`. | None. |
| `Apple token refresh failed: invalid_grant` | Refresh token revoked / expired on Apple's side. | Connection `markDisconnected( 'Refresh token revoked or expired.' )`. |
| `Apple token refresh failed: <other>` | Any other Apple error. | **None** — caller can retry after fixing config. |
| `Apple token refresh response is missing access_token.` | Apple returned 2xx with no `access_token`. | None. |

## Handling

Catch by type. Distinguish user-facing "please reconnect" errors from transient failures:

```php
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;

try {
    $token = $apple->accessTokenFor( $connection );
} catch ( TokenRefreshException $e ) {
    // The manager may have flipped the connection to disconnected.
    $connection->refresh();

    if ( ! $connection->isConnected() ) {
        return response()->view( 'settings.reconnect-apple', [], 409 );
    }

    throw $e; // transient — bubble up for the app's error handler
}
```

Both types extend `\RuntimeException`, so a generic `catch ( RuntimeException $e )` catches them if you don't want to distinguish. Prefer typed catches — the class distinction encodes which layer of the flow broke.
