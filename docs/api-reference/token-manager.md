---
title: TokenManager
---

# `TokenManager`

`ArtisanPackUI\AppleOAuth\Tokens\TokenManager` handles persistence and refresh of Apple OAuth tokens. Since 1.1.0, refreshes go through the stateless [`AppleClient`](API-Reference/Apple-Client), or through the [`BrokerClient`](API-Reference/Broker-Client) in [broker mode](Broker-Mode). Prose walkthrough: [Tokens](Tokens). This page is the terse method reference.

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

Since 1.1.0 it also writes `scopes` when the response reports any (the broker does, but Apple doesn't). It keeps the stored `apple_user_id` when `profile` is null. It throws `OAuthException` when `$response->userId` is null, so call `withUserId()` on a stateless response first.

### `getValidAccessToken()`

```php
public function getValidAccessToken( AppleConnection $connection ): string
```

Return a currently-valid access token, refreshing if the current one is expired (or within 60 seconds of expiry).

Throws [`TokenRefreshException`](API-Reference/Exceptions#tokenrefreshexception) when:

- The connection is disconnected.
- The connection is expired and has no refresh token on file.
- The refresh request to Apple (or the broker) fails.
- The broker reports a lapsed license. This throws `LicenseExpiredException`, and the connection stays connected.
- Apple's refresh response omits `access_token`.

### `refresh()`

```php
public function refresh( AppleConnection $connection ): string
```

Force a refresh regardless of expiry. Bypasses the `isExpired()` check and always POSTs to Apple's `/auth/token`, or to the broker's `/api/v1/oauth/refresh` in broker mode.

Same throw semantics as `getValidAccessToken()`. When Apple or the broker returns `error=invalid_grant`, the connection is `markDisconnected()`ed with reason `"Refresh token revoked or expired."` before the exception is thrown.

Refreshes of one connection are serialized with a cache lock (`apple-oauth:refresh:{id}`, held up to 30 seconds) when the cache store supports locks. After taking the lock, and again if the refresh fails, the stored connection is re-read: if another request saved a newer, unexpired access token meanwhile, that token is loaded onto the model and returned instead. So a refresh that loses a race to a rotating broker (`refresh_superseded`, or `invalid_grant` after the winner saved) never disconnects a working connection.

## Failure-mode summary

| Exception message | Trigger |
|---|---|
| `Apple connection is disconnected.` | Connection's `status` is `disconnected`. |
| `No refresh token stored for this connection.` | `refresh_token` column empty. Connection is `markDisconnected()`ed with `"Missing refresh token."`. |
| `Apple OAuth credentials are not configured.` | `client_id` empty on the credential driver. |
| `Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.` | `apple-oauth.endpoints.token` was overridden to `http://`. |
| `Apple token refresh failed: <error>` | Apple returned non-2xx. `invalid_grant` also triggers `markDisconnected( 'Refresh token revoked or expired.' )`. |
| `Apple token refresh response is missing access_token.` | Apple returned 2xx but no `access_token`. |
| `Another request is still refreshing this Apple connection.` | Waited 10 seconds for another request's refresh lock. `getError()` is `refresh_in_progress`. |
| `Apple OAuth broker credentials are not configured.` | Broker mode with no broker credentials. `getError()` is `broker_not_configured`. |
| `The Apple connection cannot be refreshed because the site license has expired.` | Broker returned 402. Thrown as [`LicenseExpiredException`](API-Reference/Exceptions#licenseexpiredexception), and the connection is **not** disconnected. |

## Related classes

- [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) — mints the `client_secret` JWT on refresh when no static override is configured.
- [`OAuthTokenProvider`](API-Reference/Token-Provider) — the default `TokenProvider` binding, delegates to `getValidAccessToken()`.
- [`AppleConnection`](API-Reference/Connection-Model) — the persisted row.
