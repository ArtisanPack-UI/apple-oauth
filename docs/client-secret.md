---
title: Client-Secret JWT
---

# Client-Secret JWT

Apple requires the OAuth `client_secret` to be a JWT signed with your `.p8` private key (P-256 / ES256). This is the biggest structural difference between Sign in with Apple and most other OAuth 2.0 providers — the `client_secret` isn't a static string, it's minted on demand and rotates. `ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator` handles this transparently.

## What it does

- **Mints** a fresh JWT on demand from `team_id`, `key_id`, `client_id`, and `private_key`.
- **Caches** the JWT inside its own validity window (minus `client_secret_leeway` seconds) so a cache-warmed JWT is never handed out on the edge of expiry.
- **Rotates** silently when the cache expires.
- **Signs** the JWT with the ES256 algorithm as Apple requires, converting OpenSSL's ASN.1 DER-encoded signature into the raw `R||S` concatenation JWT ES256 expects.

## Getting a JWT

You rarely call it directly — [`OAuthManager::handleCallback()`](Oauth/Callback) and [`TokenManager::refresh()`](Tokens) both call `generate()` when no static `client_secret` is on file. To reach the generator explicitly:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

$jwt = AppleOAuth::clientSecret()->generate();
```

## JWT claims

The minted JWT carries the claims Apple documents:

| Claim | Value |
|---|---|
| `alg` (header) | `ES256` |
| `kid` (header) | The configured `key_id`. |
| `typ` (header) | `JWT` |
| `iss` | The configured `team_id`. |
| `iat` | `time()` at mint. |
| `exp` | `time() + client_secret_ttl`. |
| `aud` | `https://appleid.apple.com`. |
| `sub` | The configured `client_id` (Services ID). |

## Caching

`ClientSecretGenerator` uses the framework `CacheRepository` (Laravel's default cache store). Cache key:

```
apple-oauth.client-secret.<sha256(team_id|key_id|client_id)>
```

Values are cached for `client_secret_ttl - client_secret_leeway` seconds. `client_secret_leeway` defaults to 30 seconds — so a JWT with a 3600-second TTL is cached for 3570 seconds, guaranteeing that a warmed value handed out on the last cached read still has 30 seconds of validity in front of it.

Rotating credentials (a new `.p8` key) doesn't invalidate the cache automatically because the key changes but the fingerprint (`team_id|key_id|client_id`) can technically stay the same. Discard the cache manually after a rotation:

```php
AppleOAuth::clientSecret()->forget();
```

## TTL and leeway

Two config keys control the lifetime:

| Key | Env | Default | Clamped to |
|---|---|---|---|
| `apple-oauth.client_secret_ttl` | `APPLE_OAUTH_CLIENT_SECRET_TTL` | `3600` | `[60, 15_777_000]` (60s to Apple's six-month max) |
| `apple-oauth.client_secret_leeway` | `APPLE_OAUTH_CLIENT_SECRET_LEEWAY` | `30` | Never allowed to equal or exceed the TTL |

Values below the minimums fall back to defaults; values above the maxima are clamped. So `APPLE_OAUTH_CLIENT_SECRET_TTL=30` is treated as `3600` (the minimum kicks in), and `APPLE_OAUTH_CLIENT_SECRET_TTL=99999999` is clamped to `15_777_000`.

Why the six-month max? Apple documents it as the cap for `client_secret` JWTs. Shorter windows keep the blast radius of a leaked JWT small; the default 3600 is a reasonable middle ground.

## P-256 curve enforcement

ES256 is defined only over the P-256 curve. `ClientSecretGenerator` verifies the loaded key's curve is either `prime256v1` (SEC name) or `secp256r1` (NIST name) — anything else, including P-384, throws:

```
Apple OAuth private_key must be a P-256 (prime256v1) EC key; ES256 does not accept other curves.
```

A P-384 key would otherwise pass the OpenSSL `EC` type check and produce a truncated (invalid) `R||S` signature — you'd get an opaque "invalid_client" from Apple. The up-front check makes the misconfiguration obvious.

Apple's Sign in with Apple keys are P-256 by default; you only hit this check if you brought your own key or mistakenly re-used a P-384 key from another integration.

## Path or inline PEM

The generator reads `private_key` from the [credential driver](Drivers) and auto-detects whether it's a path or inline PEM:

- If the trimmed value starts with `-----BEGIN`, it's treated as inline PEM.
- Otherwise, it's treated as a filesystem path and read with `file_get_contents()`.

Missing / unreadable path → `OAuthException("Apple OAuth private_key file is not readable: <path>")`. Unparseable key material → `OAuthException("Apple OAuth private_key could not be parsed.")`.

Prefer the path form in production — `php artisan config:cache` freezes an inline PEM into `bootstrap/cache/config.php` in cleartext.

## Pre-minted static override

For local testing you can bypass the signer entirely by setting a pre-minted JWT string:

```env
APPLE_OAUTH_CLIENT_SECRET=eyJhbGciOi...
```

When set, [`OAuthManager::handleCallback()`](Oauth/Callback) and [`TokenManager::refresh()`](Tokens) use the string verbatim and never call the generator. `team_id`, `key_id`, and `private_key` are no longer required for the code exchange. Intended for local testing; production should let the signer mint on demand.

## Rotating the `.p8` key

1. Generate a new key in Apple Developer → Keys.
2. Update `APPLE_OAUTH_KEY_ID` and `APPLE_OAUTH_PRIVATE_KEY` (or persist via the [`database`](Drivers/Database) / [`cms`](Drivers/CMS) driver).
3. `AppleOAuth::clientSecret()->forget()` to discard the cached JWT.
4. Revoke the old key in Apple Developer once you've confirmed the new one mints valid JWTs.

The generator's cache key includes `key_id`, so a rotated key with a fresh `key_id` naturally has a different cache key — but `forget()` is the safe belt-and-braces move if the old and new keys happen to share fingerprints.

## Failure-mode summary

| Exception message | Cause |
|---|---|
| `Apple client-secret requires team_id, key_id, and client_id.` | One of the three is empty on the credential driver. |
| `Apple OAuth private_key is not configured.` | `private_key` is empty. |
| `Apple OAuth private_key file is not readable: <path>` | Path exists but isn't readable, or doesn't exist. |
| `Apple OAuth private_key could not be parsed.` | `openssl_pkey_get_private()` returned false — PEM is malformed or wrong format. |
| `Apple OAuth private_key must be a P-256 (prime256v1) EC key; ES256 does not accept other curves.` | Wrong curve (P-384, secp384r1, etc.) or wrong key type (RSA). |
| `Failed to sign Apple client-secret JWT.` | `openssl_sign()` returned false — usually a broken OpenSSL install. |
| `Malformed ECDSA signature: missing SEQUENCE tag.` / `... missing INTEGER tag.` | DER parsing found unexpected bytes — the key signed but returned garbage. Never seen in practice. |
