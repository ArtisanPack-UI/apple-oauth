---
title: OAuthManager
---

# `OAuthManager`

`ArtisanPackUI\AppleOAuth\OAuth\OAuthManager` drives Sign in with Apple's OAuth 2.0 authorization-code flow. `authorizationUrl()` builds the URL to redirect the user to; `handleCallback()` verifies the returned state, redeems the authorization code, and captures the user's identity.

Prose walkthrough: [OAuth Flow](Oauth). This page is the terse method reference.

## Constants

| Constant | Value | Purpose |
|---|---|---|
| `SESSION_STATE` | `apple-oauth.state` | Session key for the anti-forgery `state`. |
| `SESSION_NONCE` | `apple-oauth.nonce` | Session key for the id_token `nonce`. |
| `SESSION_USER_ID` | `apple-oauth.user_id` | Session key for the app-side user id. |
| `APPLE_ISSUER` | `https://appleid.apple.com` | Expected `iss` claim on the id_token. |

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

Returns a [`TokenResponse`](#tokenresponse). Throws [`OAuthException`](API-Reference/Exceptions#oauthexception) on state mismatch, missing session context, non-HTTPS token endpoint, token-exchange failure, missing required token fields, or any id_token claim mismatch.

## `TokenResponse`

`ArtisanPackUI\AppleOAuth\OAuth\TokenResponse` — the immutable value object returned by `handleCallback()`.

```php
final class TokenResponse
{
    public function __construct(
        public readonly int|string $userId,
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $idToken,
        public readonly string $tokenType,
        public readonly ?Carbon $expiresAt,
        public readonly AppleUserProfile $profile,
    ) {}
}
```

| Property | Notes |
|---|---|
| `userId` | Echoed from the session; the value passed to `authorizationUrl()`. |
| `accessToken` | Short-lived Apple access token. |
| `refreshToken` | Long-lived refresh token; `null` on re-authorizations after the first. |
| `idToken` | Raw id_token JWT. Not verified for signature by this package. |
| `tokenType` | Apple always returns `Bearer`. |
| `expiresAt` | `Carbon::now()->addSeconds($expiresIn)` when Apple returned `expires_in`, else `null`. |
| `profile` | [`AppleUserProfile`](Connection-Model#appleuserprofile) with the merged id_token + one-shot `user` payload identity. |

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
    ) {}

    public function hasName(): bool;
}
```

## Related classes

- [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) — mints the ES256 `client_secret` JWT when no static override is configured.
- [`ScopeRegistry`](API-Reference/Scope-Registry) — supplies the scope list `authorizationUrl()` uses when no `$override` is passed.
- [`TokenManager`](API-Reference/Token-Manager) — persists the returned `TokenResponse`.
