---
title: Environment Variables
---

# Environment Variables

Every env var `artisanpack-ui/apple-oauth` reads, what it maps to, and what happens when it's absent.

## Driver selection

| Env | Default | Config key | Meaning |
|---|---|---|---|
| `APPLE_OAUTH_DRIVER` | `config` | `apple-oauth.driver` | Which [credential driver](Drivers) resolves at runtime: `config`, `database`, or `cms`. |

## Credential values

Only the `config` driver reads these directly. The `database` and `cms` drivers persist the same values through `save()` — see their own pages under [Credential Drivers](Drivers).

| Env | Config key | Required? | Meaning |
|---|---|---|---|
| `APPLE_OAUTH_CLIENT_ID` | `apple-oauth.client_id` | Always | The Services ID from Apple Developer. |
| `APPLE_OAUTH_TEAM_ID` | `apple-oauth.team_id` | For the ES256 signer | The 10-character Apple Developer team ID. Not needed when `APPLE_OAUTH_CLIENT_SECRET` is set. |
| `APPLE_OAUTH_KEY_ID` | `apple-oauth.key_id` | For the ES256 signer | The 10-character Key ID of the `.p8` used to sign the client secret. Not needed when `APPLE_OAUTH_CLIENT_SECRET` is set. |
| `APPLE_OAUTH_PRIVATE_KEY` | `apple-oauth.private_key` | For the ES256 signer | Absolute path to the `.p8` file, or inline PEM. **Prefer the path form** — `php artisan config:cache` freezes `env()` reads into `bootstrap/cache/config.php`, so an inline PEM would be persisted in cleartext inside the cache file. |
| `APPLE_OAUTH_CLIENT_SECRET` | `apple-oauth.client_secret` | Optional | Pre-minted JWT string. When set, bypasses the ES256 signer entirely — `team_id`, `key_id`, and `private_key` are no longer required for the code exchange. Intended for local testing; production should let the signer mint on demand. |
| `APPLE_OAUTH_REDIRECT_URI` | `apple-oauth.redirect_uri` | Always | Absolute HTTPS URL registered with the Services ID's Return URLs. |

## Client-secret JWT lifetime

| Env | Config key | Default | Meaning |
|---|---|---|---|
| `APPLE_OAUTH_CLIENT_SECRET_TTL` | `apple-oauth.client_secret_ttl` | `3600` | Lifetime of minted `client_secret` JWTs, in seconds. Clamped to Apple's six-month max (`15_777_000`). Values below 60 fall back to the default. |
| `APPLE_OAUTH_CLIENT_SECRET_LEEWAY` | `apple-oauth.client_secret_leeway` | `30` | Seconds subtracted from `client_secret_ttl` when caching, so a warmed JWT is never handed out on the edge of expiry. Clamped so it never consumes the full TTL. |

Details: [Client-Secret JWT](Client-Secret).

## User model

| Env | Config key | Default | Meaning |
|---|---|---|---|
| `APPLE_OAUTH_USER_MODEL` | `apple-oauth.user_model` | `App\Models\User` | Fully-qualified class name of the app-side user model an `apple_connections` row belongs to. Set this if you use a non-default user model or a User class outside `App\Models\`. |

## Endpoints (rarely set)

Apple's endpoints are not env-configurable by design — they're hard-coded on the config repository at:

```php
'endpoints' => [
    'authorize' => 'https://appleid.apple.com/auth/authorize',
    'token'     => 'https://appleid.apple.com/auth/token',
],
```

Override them directly in `config/apple-oauth.php` if you need a test double (e.g. pointing at a mock server in integration tests). Both endpoints must be HTTPS — the token exchange refuses to transmit `client_secret` over cleartext HTTP.

## Example `.env`

```env
# Driver
APPLE_OAUTH_DRIVER=config

# Credentials
APPLE_OAUTH_CLIENT_ID=com.acme.app.web
APPLE_OAUTH_TEAM_ID=ABCDE12345
APPLE_OAUTH_KEY_ID=XXXXXXXXXX
APPLE_OAUTH_PRIVATE_KEY=/Users/you/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
APPLE_OAUTH_REDIRECT_URI=https://app.acme.test/apple/callback

# Client-secret JWT (defaults are usually fine)
# APPLE_OAUTH_CLIENT_SECRET_TTL=3600
# APPLE_OAUTH_CLIENT_SECRET_LEEWAY=30

# User model (only if non-default)
# APPLE_OAUTH_USER_MODEL="App\\Models\\Account"
```
