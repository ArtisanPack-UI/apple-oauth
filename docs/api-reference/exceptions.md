---
title: Exceptions
---

# Exceptions

`artisanpack-ui/apple-oauth` throws three exception types. `OAuthException` and `TokenRefreshException` both extend `RuntimeException`, and `LicenseExpiredException` extends `TokenRefreshException`. Catch by type, and switch on the class or on `getError()`, never on the message string.

## Error codes

*Since 1.1.0.* Both base exceptions use the `CarriesOAuthError` trait, which gives them a machine-readable OAuth error code next to the translated message:

```php
public function __construct(
    string $message = '',
    ?string $error = null,      // e.g. 'invalid_grant', 'license_expired'
    ?string $renewUrl = null,   // only for license_expired
    ?Throwable $previous = null,
);

public function getError(): ?string;
public function getRenewUrl(): ?string;
```

| `getError()` | Source |
|---|---|
| Apple's or the broker's `error` field | Any rejected exchange or refresh (`invalid_grant`, `invalid_client`, …). |
| `exchange_failed` | Failed code exchange with an unparseable body, or a 2xx missing `access_token` / `id_token` / `sub`. |
| `refresh_failed` | Failed refresh with an unparseable body, or a 2xx missing `access_token`. |
| `insecure_endpoint` | `apple-oauth.endpoints.token` isn't HTTPS. |
| `invalid_client` | Refresh attempted with an empty `client_id`. |
| `broker_not_configured` | Refresh attempted in broker mode with no broker credentials. |
| `license_expired` | The broker refused a refresh with HTTP 402. Thrown as `LicenseExpiredException`. |
| `null` | Validation failures that have no OAuth code, such as state mismatch or a claim mismatch. |

> **Do not pass `$e->getMessage()` straight to the browser.** The messages below embed interpolated diagnostic values (`<error>` from Apple's response body, `<iss>` from a mismatched id_token issuer, `<path>` for a missing `.p8` file on the deploy host) that reveal internal state. Log the raw exception for operators (`Log::warning( ..., [ 'exception' => $e ] )`) and render a fixed, translated user-facing message from the exception **class**, not from `$e->getMessage()`. The tables here are the operator-facing reference; treat every message as internal.

## `OAuthException`

`ArtisanPackUI\AppleOAuth\Exceptions\OAuthException`

Thrown by [`OAuthManager`](API-Reference/OAuth-Manager), [`AppleClient`](API-Reference/Apple-Client), [`BrokerClient`](API-Reference/Broker-Client), and [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) when the OAuth negotiation itself fails.

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

### Broker mode and stateless use (since 1.1.0)

| Message | Cause |
|---|---|
| `Apple OAuth broker credentials are not configured.` | Broker mode with an empty broker URL, site ID, or site secret. |
| `Set apple-oauth.broker.return_url to use the Apple OAuth broker.` | Broker mode with no `broker.return_url`. |
| `The Apple OAuth broker URL must use HTTPS; plain HTTP is only allowed for local development hosts.` | `BrokerCredentials` built with an insecure URL. |
| `Apple code exchange failed: <error>` | The broker's `/token` rejected the one-time code. `getRenewUrl()` is set when the broker sent one. |
| `The Apple OAuth broker response is missing an id_token with a sub claim.` | The broker's `/token` response can't be tied to an Apple account. |
| `Cannot store an Apple token response without a user; call withUserId() first.` | `TokenManager::store()` was given a stateless `TokenResponse`. |

### Thrown by `ClientSecretGenerator::generate()` / `generateFor()`

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

Thrown by [`TokenManager`](API-Reference/Token-Manager), `AppleClient::refresh()`, `BrokerClient::refresh()`, and every [`TokenProvider`](API-Reference/Token-Provider) implementation when a valid access token can't be produced.

| Message | Cause | Side effect |
|---|---|---|
| `Apple connection is disconnected.` | Connection `status` is `disconnected`. | None — the connection stays disconnected. |
| `No refresh token stored for this connection.` | `refresh_token` column empty. | Connection `markDisconnected( 'Missing refresh token.' )`. |
| `Apple OAuth credentials are not configured.` | `client_id` empty on the credential driver. | None. |
| `Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.` | `apple-oauth.endpoints.token` overridden to `http://`. | None. |
| `Apple token refresh failed: invalid_grant` | Refresh token revoked / expired on Apple's side. | Connection `markDisconnected( 'Refresh token revoked or expired.' )`. |
| `Apple token refresh failed: <other>` | Any other Apple error. | **None** — caller can retry after fixing config. |
| `Apple token refresh response is missing access_token.` | Apple returned 2xx with no `access_token`. | None. |
| `Apple OAuth broker credentials are not configured.` | Broker mode with no broker credentials (`broker_not_configured`). | None. |

## `LicenseExpiredException`

*Since 1.1.0.* `ArtisanPackUI\AppleOAuth\Exceptions\LicenseExpiredException` extends `TokenRefreshException`.

Thrown in [broker mode](Broker-Mode) when the broker refuses a refresh because the site's plugin license has lapsed past its grace period (HTTP `402` / `error=license_expired`). The message is `The Apple connection cannot be refreshed because the site license has expired.`

**The connection stays connected.** Unlike a revoked grant, the Apple authorization is still valid, so refreshes resume once the license is renewed at `getRenewUrl()`, and the user doesn't need to reconnect.

## Handling

Catch by type. Distinguish user-facing "please reconnect" errors from transient failures:

```php
use ArtisanPackUI\AppleOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;

try {
    $token = $apple->accessTokenFor( $connection );
} catch ( LicenseExpiredException $e ) {
    // Broker mode only. The connection is still connected.
    return response()->view( 'settings.renew-license', [ 'renewUrl' => $e->getRenewUrl() ], 402 );
} catch ( TokenRefreshException $e ) {
    // The manager may have flipped the connection to disconnected.
    $connection->refresh();

    if ( ! $connection->isConnected() ) {
        return response()->view( 'settings.reconnect-apple', [], 409 );
    }

    throw $e; // transient — bubble up for the app's error handler
}
```

All three types extend `\RuntimeException`, so a generic `catch ( RuntimeException $e )` catches them if you don't want to distinguish. Prefer typed catches — the class distinction encodes which layer of the flow broke.
