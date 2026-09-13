---
title: Requirements
---

# Requirements

## PHP

- **PHP 8.2 or newer.** The package uses constructor property promotion, readonly properties, and named arguments throughout.
- **`openssl` extension.** Required to load the P-256 `.p8` private key and sign the ES256 client-secret JWT. Bundled with every mainstream PHP build.
- **`json` extension.** Standard with PHP.

## Laravel

- **Laravel 10.x, 11.x, 12.x, or 13.x.** The package requires `illuminate/support` on the matching major.

The service provider auto-registers via Laravel's package discovery.

## Required ArtisanPack packages

- **`artisanpack-ui/core` ^1.0** — pulled in transitively.
- **`artisanpack-ui/hooks` ^1.2** — the [scope registry](Scopes) uses the `ap.apple-oauth.scopes` filter hook to collect scopes from service packages.

## Optional peer packages

| Package | Version | What it enables |
|---|---|---|
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | any | The `cms` credential driver — stores credentials via the framework's Settings module (see [cms driver](Drivers/CMS)). |

Not required. The base package boots and works without it; the `cms` driver throws a `RuntimeException` at construction time if you set `APPLE_OAUTH_DRIVER=cms` without the framework installed, so the misconfiguration is discoverable rather than silently broken.

## Apple Developer

- **Active [Apple Developer Program](https://developer.apple.com/programs/) membership.** Sign in with Apple is not available on free accounts.
- **An App ID with Sign in with Apple enabled**, plus a Services ID and a Sign in with Apple `.p8` key. See [Apple Developer Setup](Installation/Apple-Developer-Setup).

## HTTPS

- **The `redirect_uri` must be HTTPS.** Apple refuses to register `http://` return URLs on the Services ID. In development, use Laravel Herd's per-site TLS, `valet secure`, or a similar tool.
- **`endpoints.token` must be HTTPS.** [`OAuthManager`](API-Reference/OAuth-Manager) and [`TokenManager`](API-Reference/Token-Manager) both refuse to transmit the `client_secret` over cleartext HTTP and throw an exception up front rather than sending the JWT in the clear.

## Database

The package ships two tables:

- `apple_configurations` (only used by the `database` credential driver, single-row upsert anchored on a `singleton` sentinel column)
- `apple_connections` (used by every driver — tokens always live in the database)

Any Laravel-supported connection works. If you migrate on a fresh install and later switch drivers, the unused table stays empty — leaving it in place is fine.

## Encryption

The package uses Laravel's `Encrypter` (`APP_KEY`) to encrypt:

- The `private_key` and `client_secret` columns when using the `database` or `cms` credential drivers.
- The `access_token`, `refresh_token`, and `id_token` columns on every `apple_connections` row, via Eloquent's `encrypted` cast.

**Rotating `APP_KEY` without re-encrypting** the stored ciphertext will silently invalidate every stored credential ciphertext and every stored connection. The credential drivers log a warning and treat the row as unconfigured; the `apple_connections` cast will fail to decrypt, which surfaces as a `DecryptException` when the token manager runs. Plan a re-encryption pass alongside key rotations.

## Session driver

The [OAuth Flow](Oauth) uses the session to persist the CSRF `state`, id_token `nonce`, and app-side user id between the redirect to Apple and the callback. Any Laravel session driver works — cookie, file, database, redis. Just make sure sessions are enabled on the route group that handles your connect and callback routes (the default `web` middleware satisfies this).

`SESSION_SAME_SITE` must be `lax` (default) or `none` for OAuth redirects to include the session cookie. Since Apple `form_post`s back to your callback, `SameSite=Strict` will drop the session cookie and the state check will fail — see the [FAQ](FAQ) entry on state mismatches.

## Cache driver

`ClientSecretGenerator` caches minted `client_secret` JWTs in the default cache store. Any driver works. Cache misses simply mint a fresh JWT — there's no correctness issue if the cache is unavailable, only extra ES256 signing work per request.
