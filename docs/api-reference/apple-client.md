---
title: AppleClient
---

# `AppleClient`

*Since 1.1.0.*

`ArtisanPackUI\AppleOAuth\OAuth\AppleClient` provides the stateless OAuth primitives for talking to Apple directly. Nothing here touches the session or the database. Prose walkthrough: [Stateless Client](Stateless-Client). This page is the terse method reference.

## Constants

| Constant | Value |
|---|---|
| `DEFAULT_AUTHORIZE_ENDPOINT` | `https://appleid.apple.com/auth/authorize` |
| `DEFAULT_TOKEN_ENDPOINT` | `https://appleid.apple.com/auth/token` |
| `APPLE_ISSUER` | `https://appleid.apple.com` |

## Constructor

```php
public function __construct(
    protected AppleCredentials $credentials,
    protected HttpFactory $http,
    protected ClientSecretGenerator $clientSecret,
    protected string $authorizeEndpoint = self::DEFAULT_AUTHORIZE_ENDPOINT,
    protected string $tokenEndpoint = self::DEFAULT_TOKEN_ENDPOINT,
)
```

Prefer `AppleOAuth::client()`, `OAuthManager::client()`, or `AppleClient::make()`, which read the endpoints from config.

## Static methods

### `make()`

```php
public static function make(
    AppleCredentials|ConfigurationRepository $credentials,
    HttpFactory $http,
    ClientSecretGenerator $clientSecret,
    ConfigRepository $config,
): self
```

Builds a client from explicit credentials or a credential driver, using `apple-oauth.endpoints.*`.

### `verifyState()`

```php
public static function verifyState( ?string $expected, string $returned ): void
```

Timing-safe `state` compare. Throws `OAuthException("OAuth state mismatch; possible CSRF attempt.")` when `$expected` is null or empty, or doesn't match.

### `decodeIdToken()`

```php
public static function decodeIdToken( string $idToken ): array
```

Decodes the id_token claims **without verifying the signature**. Throws `OAuthException` when the JWT is malformed.

## Methods

### `credentials(): AppleCredentials`

The credentials the client authenticates with.

### `authorizationUrl()`

```php
public function authorizationUrl(
    string $state,
    array $scopes,
    ?string $nonce = null,
    array $parameters = [],
): string
```

Builds the Apple consent URL with `response_type=code` and `response_mode=form_post`. Entries in `$parameters` are merged over the defaults. Throws `OAuthException` when the client ID or redirect URI is missing.

### `exchangeCode()`

```php
public function exchangeCode(
    string $code,
    ?string $nonce = null,
    ?string $userPayload = null,
): TokenResponse
```

Exchanges an authorization code without persisting anything. Validates the id_token's `iss`, `aud`, `exp`, and `sub` claims. When `$nonce` isn't null, it also validates `nonce`, and an empty string always fails. Merges the one-shot `user` payload's name into the profile. Returns a [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse) with `userId === null`.

Throws `OAuthException` when Apple rejects the exchange (`getError()` holds Apple's code), the token endpoint isn't HTTPS, a required field is missing, or a claim fails.

### `refresh()`

```php
public function refresh( string $refreshToken, string $tokenType = 'Bearer' ): TokenResponse
```

Refreshes from a raw refresh-token string. The returned response carries the refresh token you passed in, or a rotated one. Its profile comes from the id_token when Apple includes one, and is null otherwise.

Throws `TokenRefreshException`. `getError()` is `invalid_grant` for a revoked grant, `invalid_client` when the client ID is empty, `insecure_endpoint` for a non-HTTPS token endpoint, and `refresh_failed` for an unparseable error or a missing `access_token`.

### `clientSecret(): string`

The `client_secret` to send: the static override on the credentials if set, else a cached ES256 JWT from [`ClientSecretGenerator::generateFor()`](API-Reference/Client-Secret-Generator#generatefor).

## `AppleCredentials`

`ArtisanPackUI\AppleOAuth\OAuth\AppleCredentials` is an immutable set of Sign in with Apple app credentials.

```php
final class AppleCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $teamId = null,
        public readonly ?string $keyId = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $redirectUri = null,
        public readonly ?string $clientSecret = null,
    ) {}

    public static function fromRepository( ConfigurationRepository $repository ): self;
}
```

| Property | Notes |
|---|---|
| `clientId` | Services ID. |
| `teamId`, `keyId`, `privateKey` | Signer inputs. `privateKey` is inline PEM or a `.p8` path. Not required when `clientSecret` is set. |
| `redirectUri` | Needed only for the consent URL and the code exchange. |
| `clientSecret` | Pre-minted JWT. Bypasses the signer. |

`fromRepository()` reads every value from a [credential driver](API-Reference/Configuration-Repository) and normalizes empty strings to `null`.
