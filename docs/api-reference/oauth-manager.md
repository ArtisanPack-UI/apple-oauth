---
title: OAuthManager
---

# `OAuthManager`

`ArtisanPackUI\AppleOAuth\OAuth\OAuthManager` drives Sign in with Apple's OAuth 2.0 authorization-code flow. `authorizationUrl()` builds the URL to redirect the user to; `handleCallback()` verifies the returned state, redeems the authorization code, and captures the user's identity.

Since 1.1.0 the Apple calls go through the stateless [`AppleClient`](API-Reference/Apple-Client), or through the [`BrokerClient`](API-Reference/Broker-Client) when `apple-oauth.mode` is `broker`. The behavior of the default `direct` mode hasn't changed.

Prose walkthrough: [OAuth Flow](Oauth). This page is the terse method reference.

## Constants

| Constant | Value | Purpose |
|---|---|---|
| `SESSION_STATE` | `apple-oauth.state` | Session key for the anti-forgery `state`. |
| `SESSION_NONCE` | `apple-oauth.nonce` | Session key for the id_token `nonce`. |
| `SESSION_USER_ID` | `apple-oauth.user_id` | Session key for the app-side user id. |
| `APPLE_ISSUER` | `https://appleid.apple.com` | Expected `iss` claim on the id_token. Aliases `AppleClient::APPLE_ISSUER`. |

All are `protected` — listed for readers reading source. If you need them from outside, they're stable strings.

## Constructor

```php
public function __construct(
    protected ConfigRepository $config,
    protected Session $session,
    protected HttpFactory $http,
    protected ClientSecretGenerator $clientSecret,
    protected ScopeRegistry $scopes,
    protected ConfigurationRepository $credentials,
)
```

Bound as a singleton by the service provider.

## Methods

### `authorizationUrl()`

```php
public function authorizationUrl(
    int|string $userId,
    ?array $override = null,
): string
```

Build the Apple authorization URL for a given user. Details: [OAuth → Connect](Oauth/Connect).

- `$userId` — app-side user id. Stashed in the session under `apple-oauth.user_id`.
- `$override` — explicit scope list. Defaults to the [`ScopeRegistry`](API-Reference/Scope-Registry) union.

Returns the URL string. Throws [`OAuthException`](API-Reference/Exceptions#oauthexception) when required credentials are missing.

The built URL always uses `response_mode=form_post` — Apple gates the one-shot `user` payload on that response mode.

In broker mode it returns the signed broker `/authorize` link instead and stores only `state` and the user ID (the broker runs the nonce). It throws when the broker or `apple-oauth.broker.return_url` isn't configured. See [Broker Mode → Connect](Broker-Mode#connect).

### `handleCallback()`

```php
public function handleCallback(
    string $code,
    string $returnedState,
    ?string $userPayload = null,
): TokenResponse
```

Handle the callback Apple form-posts to your `redirect_uri`. Details: [OAuth → Callback](Oauth/Callback).

- `$code` — authorization code from Apple's response body.
- `$returnedState` — the `state` Apple echoed back. Compared to the session-stored value with `hash_equals()`.
- `$userPayload` — raw JSON `user` field. Present only on the first authorization; `null` on subsequent callbacks.

Returns a [`TokenResponse`](#tokenresponse) with `userId` set from the session. Throws [`OAuthException`](API-Reference/Exceptions#oauthexception) on state mismatch, missing session context, non-HTTPS token endpoint, token-exchange failure, missing required token fields, or any id_token claim mismatch.

In broker mode, `$code` is the broker's one-time code and `$userPayload` is ignored, because the broker relays the name as `account_name`. See [Broker Mode → Callback](Broker-Mode#callback).

### `client()`

```php
public function client( ?AppleCredentials $credentials = null ): AppleClient
```

*Since 1.1.0.* Returns a stateless [`AppleClient`](API-Reference/Apple-Client) built from explicit credentials or the configured driver. `AppleOAuth::client()` delegates here.

### `brokerClient()`

```php
public function brokerClient(): BrokerClient
```

*Since 1.1.0.* Returns a [`BrokerClient`](API-Reference/Broker-Client) built from the configured broker credentials. Throws `OAuthException` when the broker isn't configured.

### `usesBroker()`

```php
public function usesBroker(): bool
```

*Since 1.1.0.* Returns whether `apple-oauth.mode` is `broker`.

### `isTrustedRenewUrl()`

```php
public function isTrustedRenewUrl( ?string $url ): bool
```

*Since 1.1.0.* Returns whether a license `renew_url` from the broker's return is safe to show the user. It's `true` only in broker mode, for URLs on the broker's own host that a browser will also resolve to that host. Delegates to [`BrokerClient::isTrustedRenewUrl()`](API-Reference/Broker-Client), which lists the full rules; since 1.2.0 that includes rejecting backslashes, whitespace, control characters and userinfo. The `renew_url` arrives on the query string, so anyone can forge it, so check it here before linking to it. Returns `false` (rather than throwing) when the broker isn't configured.

## `TokenResponse`

`ArtisanPackUI\AppleOAuth\OAuth\TokenResponse` — the immutable value object returned by `handleCallback()`, by the stateless [`AppleClient`](API-Reference/Apple-Client), and by the [`BrokerClient`](API-Reference/Broker-Client). Nothing about it is persisted.

```php
final class TokenResponse
{
    public function __construct(
        public readonly int|string|null $userId,
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $idToken,
        public readonly string $tokenType,
        public readonly ?Carbon $expiresAt,
        public readonly ?AppleUserProfile $profile,
        public readonly ?int $expiresIn = null,
        public readonly array $scopes = [],
    ) {}

    public static function fromApple( array $payload, ?AppleUserProfile $profile, ?string $fallbackRefreshToken = null, string $fallbackTokenType = 'Bearer' ): self;
    public static function fromBroker( array $payload, ?string $fallbackRefreshToken = null, string $fallbackTokenType = 'Bearer' ): self;
    public function withUserId( int|string $userId ): self;
    public function toArray(): array;
}
```

| Property | Notes |
|---|---|
| `userId` | Echoed from the session by `handleCallback()`. `null` on stateless responses. *Nullable since 1.1.0.* |
| `accessToken` | Short-lived Apple access token. |
| `refreshToken` | Long-lived refresh token. `null` on re-authorizations after the first. On a refresh it's the token passed in, unless the provider rotated it. |
| `idToken` | Raw id_token JWT. Not verified for signature by this package. |
| `tokenType` | Apple always returns `Bearer`. |
| `expiresAt` | `Carbon::now()->addSeconds($expiresIn)` when `expires_in` was returned, else `null`. |
| `profile` | [`AppleUserProfile`](Connection-Model#appleuserprofile) with the merged id_token + one-shot `user` payload identity. Always set on a code exchange. `null` on a refresh without an id_token. *Nullable since 1.1.0.* |
| `expiresIn` | Access-token lifetime in seconds, when reported. *Since 1.1.0.* |
| `scopes` | Granted scopes. Empty when not reported, and Apple's token endpoint never reports them. *Since 1.1.0.* |

| Method | Since | Notes |
|---|---|---|
| `fromApple()` | 1.1.0 | Builds from an Apple `/auth/token` payload. Splits `scope` on spaces. |
| `fromBroker()` | 1.1.0 | Builds from a broker `/token` or `/refresh` payload. `sub` comes from the id_token. The broker's `account_email` / `account_name` take precedence over its claims. `profile` is `null` without a usable id_token. |
| `withUserId()` | 1.1.0 | Returns a copy attributed to an app-side user. Required before `TokenManager::store()` on a stateless response. |
| `toArray()` | 1.1.0 | Renders the broker's site-facing wire shape: `token_type`, `access_token`, `refresh_token`, `expires_in`, `scopes`, `account_email`, `account_name` (`profile->fullName()`), `id_token`. |

Hand off to [`TokenManager::store()`](API-Reference/Token-Manager) to persist.

## `AppleUserProfile`

`ArtisanPackUI\AppleOAuth\OAuth\AppleUserProfile` — immutable identity value object. Full description: [Connection Model → AppleUserProfile](Connection-Model#appleuserprofile).

```php
final class AppleUserProfile
{
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $displayName = null, // since 1.1.0
    ) {}

    public function hasName(): bool;
    public function fullName(): ?string;             // since 1.1.0
}
```

## Related classes

- [`AppleClient`](API-Reference/Apple-Client) — the stateless primitives this manager wraps in direct mode.
- [`BrokerClient`](API-Reference/Broker-Client) — what this manager delegates to in broker mode.
- [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) — mints the ES256 `client_secret` JWT when no static override is configured.
- [`ScopeRegistry`](API-Reference/Scope-Registry) — supplies the scope list `authorizationUrl()` uses when no `$override` is passed.
- [`TokenManager`](API-Reference/Token-Manager) — persists the returned `TokenResponse`.
