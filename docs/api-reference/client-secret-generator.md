---
title: ClientSecretGenerator
---

# `ClientSecretGenerator`

`ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator` builds and caches Apple's short-lived, ES256-signed `client_secret` JWT. Prose walkthrough: [Client-Secret JWT](Client-Secret). This page is the terse method reference.

## Constants

| Constant | Value | Purpose |
|---|---|---|
| `AUDIENCE` | `https://appleid.apple.com` | JWT `aud` claim. |
| `CACHE_KEY_PREFIX` | `apple-oauth.client-secret.` | Prefix for the SHA-256 fingerprint cache key. |
| `APPLE_MAX_TTL` | `15_777_000` | Apple's documented six-month ceiling for `client_secret` JWTs. |
| `DEFAULT_TTL` | `3600` | Fallback when the configured TTL is below 60 seconds. |
| `DEFAULT_LEEWAY` | `30` | Seconds subtracted from the TTL for cache expiry. |

All are `protected` — listed for readers reading source.

## Constructor

```php
public function __construct(
    protected ConfigRepository $config,
    protected CacheRepository $cache,
    protected ConfigurationRepository $credentials,
)
```

Bound as a singleton by the service provider.

## Methods

### `generate(): string`

Return a currently-valid `client_secret` JWT, minting and caching one if the cache is empty or expired.

Cache key:

```
apple-oauth.client-secret.<sha256(team_id|key_id|client_id)>
```

Values are cached for `client_secret_ttl - client_secret_leeway` seconds.

Throws [`OAuthException`](API-Reference/Exceptions#oauthexception) when:

- `team_id`, `key_id`, or `client_id` is empty.
- `private_key` is empty.
- The path form of `private_key` isn't readable.
- The key material can't be parsed by `openssl_pkey_get_private()`.
- The loaded key isn't a P-256 EC key (`prime256v1` / `secp256r1`).
- `openssl_sign()` fails.
- The DER signature returned by OpenSSL is malformed.

### `forget(): void`

Discard any cached JWT so the next call mints a fresh one. Use after rotating the `.p8` key.

```php
AppleOAuth::clientSecret()->forget();
```

## Configuration inputs

Reads from:

- The [credential driver](Drivers) — `team_id`, `key_id`, `client_id`, `private_key`.
- `config('apple-oauth.client_secret_ttl')` — clamped to `[60, 15_777_000]`.
- `config('apple-oauth.client_secret_leeway')` — clamped so it never consumes the full TTL (`leeway >= ttl` falls back to `floor( $ttl / 2 )`).

## Curve enforcement

`loadPrivateKey()` verifies:

1. `openssl_pkey_get_details()` returns a valid struct.
2. `type === OPENSSL_KEYTYPE_EC`.
3. `ec.curve_name ∈ { 'prime256v1', 'secp256r1' }`.

Any of these failing throws:

```
Apple OAuth private_key must be a P-256 (prime256v1) EC key; ES256 does not accept other curves.
```

## DER → raw signature conversion

OpenSSL's `openssl_sign()` emits an ASN.1 DER-encoded ECDSA signature. ES256 requires the raw `R||S` concatenation (32 bytes each for P-256). `ClientSecretGenerator::derToRawSignature()` parses the DER, extracts `R` and `S`, strips any leading zero bytes DER uses to mark positivity, and left-pads each to 32 bytes.

Callers never see this transformation — `generate()` returns the finished, base64url-encoded JWT ready to send to Apple.
