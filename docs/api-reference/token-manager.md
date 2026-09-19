---
title: TokenManager
---

# `TokenManager`

`ArtisanPackUI\AppleOAuth\Tokens\TokenManager` handles persistence and refresh of Apple OAuth tokens. Prose walkthrough: [Tokens](Tokens). This page is the terse method reference.

## Constructor

```php
public function __construct(
    protected ConfigRepository $config,
    protected HttpFactory $http,
    protected ClientSecretGenerator $clientSecret,
    protected ConfigurationRepository $credentials,
)
```

Bound as a singleton by the service provider.

## Methods

### `store()`

```php
public function store( TokenResponse $response ): AppleConnection
```

Persist a [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse) as an encrypted [`AppleConnection`](API-Reference/Connection-Model) row for the response's user.

If a connection already exists for the user, it's updated in place so subsequent authorizations refresh identity and tokens rather than creating orphaned rows.

Preserves the stored `email` when the incoming profile omits it (Apple only releases `email` on the first authorization). Preserves the stored `refresh_token` when the incoming `refreshToken` is null or empty (Apple only issues refresh tokens on the initial grant).

Sets `status = 'connected'` and clears `disconnect_reason` — a re-connect after a `markDisconnected()` flips the status back automatically.

### `getValidAccessToken()`

```php
public function getValidAccessToken( AppleConnection $connection ): string
```

Return a currently-valid access token, refreshing if the current one is expired (or within 60 seconds of expiry).

Throws [`TokenRefreshException`](API-Reference/Exceptions#tokenrefreshexception) when:

- The connection is disconnected.
- The connection is expired and has no refresh token on file.
- The refresh request to Apple fails.
- Apple's refresh response omits `access_token`.

### `refresh()`

```php
public function refresh( AppleConnection $connection ): string
```

Force a refresh regardless of expiry. Bypasses the `isExpired()` check and always POSTs to `/auth/token`.

Same throw semantics as `getValidAccessToken()`. When Apple returns `error=invalid_grant`, the connection is `markDisconnected()`ed with reason `"Refresh token revoked or expired."` before the exception is thrown.

## Failure-mode summary

| Exception message | Trigger |
|---|---|
| `Apple connection is disconnected.` | Connection's `status` is `disconnected`. |
| `No refresh token stored for this connection.` | `refresh_token` column empty. Connection is `markDisconnected()`ed with `"Missing refresh token."`. |
| `Apple OAuth credentials are not configured.` | `client_id` empty on the credential driver. |
| `Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.` | `apple-oauth.endpoints.token` was overridden to `http://`. |
| `Apple token refresh failed: <error>` | Apple returned non-2xx. `invalid_grant` also triggers `markDisconnected( 'Refresh token revoked or expired.' )`. |
| `Apple token refresh response is missing access_token.` | Apple returned 2xx but no `access_token`. |

## Related classes

- [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) — mints the `client_secret` JWT on refresh when no static override is configured.
- [`OAuthTokenProvider`](API-Reference/Token-Provider) — the default `TokenProvider` binding, delegates to `getValidAccessToken()`.
- [`AppleConnection`](API-Reference/Connection-Model) — the persisted row.
