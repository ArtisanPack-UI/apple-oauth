---
title: BrokerClient
---

# `BrokerClient`

*Since 1.1.0.*

`ArtisanPackUI\AppleOAuth\Broker\BrokerClient` runs the connect, exchange, and refresh steps through an OAuth broker. It implements the site-facing side of the broker contract: signed `/authorize` links, the one-time code exchange at `/token`, and refreshes at `/refresh`. Prose walkthrough: [Broker Mode](Broker-Mode).

## Constants

| Constant | Value | Purpose |
|---|---|---|
| `PROVIDER` | `apple` | Provider segment in broker URLs and the signature payload. |
| `LINK_TTL_SECONDS` | `300` | How long a signed `/authorize` link stays valid. The broker accepts at most 10 minutes. |

## Constructor

```php
public function __construct(
    protected BrokerCredentials $credentials,
    protected HttpFactory $http,
)
```

Usually obtained through `AppleOAuth::broker()` or `OAuthManager::brokerClient()`.

## Static methods

### `isEnabled( ConfigRepository $config ): bool`

`true` when `apple-oauth.mode` is `broker`.

### `fromConfig( ConfigRepository $config, HttpFactory $http ): self`

Builds a client from [`BrokerCredentials::fromConfig()`](#brokercredentials). Throws `OAuthException("Apple OAuth broker credentials are not configured.")` when the URL, site ID, or site secret is missing.

## Methods

### `credentials(): BrokerCredentials`

The credentials the client authenticates with.

### `authorizationUrl()`

```php
public function authorizationUrl( string $state, string $returnUrl, ?array $scopes = null ): string
```

The signed `{url}/api/v1/oauth/apple/authorize` link. Query: `site_id`, `state`, `return_url`, `expires` (now + 300s), optional `scopes` (space-separated; omitted when null or empty, which asks for every scope the broker allows), and `signature`.

### `signature( array $params ): string`

HMAC-SHA256 of `"apple\n" . http_build_query( ksort( $params ), RFC 3986 )`, keyed with [`BrokerCredentials::signingKey()`](#brokercredentials). Any `signature` key in `$params` is ignored.

### `exchangeCode( string $code ): TokenResponse`

POSTs `grant_type=authorization_code&code=…` to `{url}/api/v1/oauth/token` with the site secret as the bearer token. Returns `TokenResponse::fromBroker()`.

Throws `OAuthException` when the broker returns an error (`getError()` and `getRenewUrl()` are set), returns no `access_token` (`exchange_failed`), or returns no id_token with a `sub` claim (`exchange_failed`).

### `refresh( string $refreshToken, string $tokenType = 'Bearer' ): TokenResponse`

POSTs `refresh_token=…&provider=apple` to `{url}/api/v1/oauth/refresh`. The returned response keeps `$refreshToken`, since the broker sends none back.

| Broker result | Throws |
|---|---|
| HTTP 402, or `error=license_expired` | [`LicenseExpiredException`](API-Reference/Exceptions#licenseexpiredexception), with `getRenewUrl()` |
| Any other error | `TokenRefreshException`. `getError()` is the broker's code, e.g. `invalid_grant`. |
| 2xx without `access_token` | `TokenRefreshException` (`refresh_failed`) |

### `isTrustedRenewUrl( ?string $url ): bool`

`true` only when `$url` is on the broker's host and uses HTTPS, or uses HTTP when the broker itself is plain HTTP. Never accepts a downgrade from an HTTPS broker. Use it before showing a `renew_url` that arrived on a query string. `OAuthManager::isTrustedRenewUrl()` wraps this and also returns `false` outside broker mode.

## `BrokerCredentials`

`ArtisanPackUI\AppleOAuth\Broker\BrokerCredentials` holds the immutable credentials a site uses to talk to the broker.

```php
final class BrokerCredentials
{
    public function __construct(
        public readonly string $url,         // no trailing slash
        public readonly string $siteId,
        public readonly string $siteSecret,  // "{id}|{plain}"
    ) {}

    public static function isSecureUrl( string $url ): bool;
    public static function fromConfig( ConfigRepository $config ): ?self;
    public function signingKey(): string;
}
```

- **Constructor.** Throws `OAuthException` when `$url` isn't secure (see `isSecureUrl()`).
- **`isSecureUrl()`.** Returns `true` for HTTPS URLs. Returns `true` for HTTP only on `localhost`, `*.localhost`, `*.test`, `127.x.x.x`, and `::1`.
- **`fromConfig()`.** Reads `apple-oauth.broker.{url,site_id,site_secret}`, passes them through the `ap.apple-oauth.broker.credentials` filter, then trims them (and any trailing `/` on the URL). Returns `null` when any value is empty.
- **`signingKey()`.** Returns the SHA-256 of the plain part of the site secret (after the `|`). A secret without `|` is treated as entirely plain.
