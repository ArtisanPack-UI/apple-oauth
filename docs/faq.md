---
title: FAQ
---

# FAQ

## Setup

### Do I need a paid Apple Developer account?

Yes. Sign in with Apple is only available on active [Apple Developer Program](https://developer.apple.com/programs/) memberships — the free tier doesn't get it. See [Apple Developer Setup](Installation/Apple-Developer-Setup).

### Why is the return URL required to be HTTPS?

Apple refuses to register `http://` return URLs on the Services ID. In development, use Laravel Herd's per-site TLS, `valet secure`, or a similar tool to expose an HTTPS local URL.

The package also refuses to POST to a non-HTTPS token endpoint — sending a signed `client_secret` JWT over cleartext HTTP would leak it. If you override `apple-oauth.endpoints.token` for a test double, keep it HTTPS.

### Why is my callback CSRF-blocked?

Apple sends the callback as a `form_post` — a real form submission from Apple's origin, which Laravel's default CSRF middleware rejects because there's no matching CSRF token cookie/header.

Exempt the callback path:

- **Laravel 11+**: `validateCsrfTokens( except: [ 'apple/callback' ] )` in `bootstrap/app.php`.
- **Laravel 10**: add `'apple/callback'` to `App\Http\Middleware\VerifyCsrfToken::$except`.

The anti-forgery guarantee is the `state` parameter [`OAuthManager::handleCallback()`](Oauth/Callback) verifies against the session, not Laravel's CSRF token.

### Can I use this package without the CMS framework?

Yes. The CMS framework is only required if you set `APPLE_OAUTH_DRIVER=cms`. The default [`config` driver](Drivers/Config) and the [`database` driver](Drivers/Database) work without it. If you pick `cms` without the framework installed, the driver throws a `RuntimeException` at construction time with a message pointing at the fix — see [CMS Driver → Fail-fast](Drivers/CMS#fail-fast-when-the-framework-isnt-installed).

## Credentials

### Where should I put my `.p8` private key?

Absolute filesystem path, mode `600`, owned by the web-server user. `APPLE_OAUTH_PRIVATE_KEY` accepts either a path or inline PEM — **prefer the path**. `php artisan config:cache` freezes `env()` reads into `bootstrap/cache/config.php`, so an inline PEM would sit in the cache file in cleartext.

Development machines: `~/.config/artisanpack/AuthKey_XXXXXXXXXX.p8` owned by your user. Production: somewhere the web-server user can read, or store the key material encrypted via the [`database`](Drivers/Database) / [`cms`](Drivers/CMS) driver.

### How do I rotate the `.p8` key?

1. Generate a new key in Apple Developer → Keys → **+**.
2. Update `APPLE_OAUTH_KEY_ID` and `APPLE_OAUTH_PRIVATE_KEY` (or `save()` new values via the [`database`](Drivers/Database) / [`cms`](Drivers/CMS) driver).
3. Discard the cached JWT: `AppleOAuth::clientSecret()->forget();`.
4. Revoke the old key in Apple Developer once you've confirmed the new one mints valid JWTs.

Details: [Client-Secret JWT → Rotating the .p8 key](Client-Secret#rotating-the-p8-key).

### Can I use a P-384 key?

No. ES256 is defined only over the P-256 curve. [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) enforces this at load time — a non-P-256 key throws:

```
Apple OAuth private_key must be a P-256 (prime256v1) EC key; ES256 does not accept other curves.
```

Apple's Sign in with Apple keys are P-256 by default; you'd only hit this if you brought your own key or mistakenly re-used a P-384 key from another integration.

### What happens when I rotate `APP_KEY`?

Any encrypted values become unreadable until you re-encrypt them. Two places matter:

1. **`private_key` and `client_secret` in `apple_configurations` / CMS Settings** — the [`database`](Drivers/Database) and [`cms`](Drivers/CMS) drivers log a warning and treat the row as unconfigured. `isConfigured()` returns `false`; attempts to build an authorization URL throw `OAuthException("Apple OAuth credentials are not configured.")`.
2. **`access_token`, `refresh_token`, `id_token` in `apple_connections`** — Eloquent's `encrypted` cast throws `DecryptException` on read.

Plan a re-encryption pass alongside key rotations. If you can't, at minimum re-save the credentials via `app( ConfigurationRepository::class )->save()` — every connected user will need to reconnect (their token columns are dead) but the credential driver will start reporting configured again.

## OAuth flow

### Why is the user's email null on the second sign-in?

Apple only releases the `email` claim on the **first** authorization for a given Services ID + user pair. On the second and subsequent authorizations Apple omits it. [`TokenManager::store()`](Tokens) preserves the previously-stored `email` on updates — you don't lose it, but if you delete the `apple_connections` row and reconnect the same user, the email will already be `null` from Apple's side.

Same story for the display name — released once, on the first `user` form-post payload, never again.

### Does Apple issue a refresh token on refresh?

Not typically. Apple's `/auth/token` refresh response usually returns just an `access_token`. The OAuth2 spec permits refresh-token rotation, and [`TokenManager::refresh()`](Tokens) persists a rotated one if Apple ever returns it — so a future change on Apple's side doesn't silently break refresh.

### Why doesn't the callback verify the id_token signature?

The id_token arrives over TLS from Apple's token endpoint on a connection your app initiated. The `sub` and `email` claims are used for identity **persistence only** — labeling the connection row so you can display "Connected as {email}" in your UI. They aren't used for authorization decisions, so JWKS signature validation would be pointless overhead.

If your use case actually authorizes off the id_token (e.g., logging the user into your app via Sign in with Apple), verify the signature externally with `firebase/php-jwt` against Apple's public keys before trusting the claims.

### Why don't disconnects revoke the token with Apple?

Local-only disconnect is faster, doesn't require a network round-trip, and avoids failure modes where Apple is unreachable. The refresh token stays valid on Apple's side until:

- The user manually revokes the Sign in with Apple grant at [appleid.apple.com](https://appleid.apple.com/) under "Sign in with Apple".
- Your app hits Apple's revoke endpoint.

If you need remote revocation, POST to `https://appleid.apple.com/auth/revoke` before calling `markDisconnected()`. See [OAuth → Disconnect](Oauth/Disconnect#local-only-revocation).

### The refresh flow throws `invalid_grant`. What happened?

Usual causes:

1. **User revoked the grant** at [appleid.apple.com](https://appleid.apple.com/).
2. **Refresh token aged out** — Apple doesn't publish exact TTLs.
3. **Apple ID password changed** in some configurations.

[`TokenManager`](Tokens) marks the connection disconnected with reason `"Refresh token revoked or expired."` and throws `TokenRefreshException`. The user needs to re-run `/apple/connect`.

## Scopes

### How do I add a new scope?

Apple only exposes `name` and `email` today, and the package's baseline requests both. Additional scopes reserved for future Apple-issued grants (or a bring-your-own baseline in a downstream broker) can be contributed via the `ap.apple-oauth.scopes` filter hook:

```php
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.apple-oauth.scopes', fn ( array $s ) => [ ...$s, 'my.custom.scope' ] );
```

Or `AppleOAuth::scopes()->register( 'my.custom.scope' )` from app code. See [Scopes](Scopes).

### Is there incremental consent like Google's?

No. Apple's scope surface is fixed at `name` + `email`, so there's nothing to incrementally consent to. The registry exposes `missing()` / `hasAllRequired()` for API parity, but for a real Sign in with Apple flow the answer is always "yes, granted."

## Runtime

### The connect route throws "credentials are not configured"

Your credential driver reports `isConfigured() === false`. Check:

- `config('apple-oauth.driver')` — is it the driver you expect?
- For `config` driver: are `APPLE_OAUTH_CLIENT_ID` and `APPLE_OAUTH_REDIRECT_URI` set? Plus either `APPLE_OAUTH_CLIENT_SECRET` OR the triple `APPLE_OAUTH_TEAM_ID` / `APPLE_OAUTH_KEY_ID` / `APPLE_OAUTH_PRIVATE_KEY`?
- For `database` / `cms` driver: did you `save()` credentials? Are they still readable (i.e., did `APP_KEY` rotate)?

### The callback flashes "OAuth state mismatch"

The session lost the `apple-oauth.state` value between `/apple/connect` and the callback. Check:

- `SESSION_DRIVER` — cookie, file, database, redis all work; the null driver doesn't.
- `SESSION_SAME_SITE` — must be `lax` (default) or `none` for OAuth redirects to include the session cookie. `SameSite=Strict` will drop the cookie on Apple's `form_post` return.
- `SESSION_SECURE_COOKIE` — must be `false` for `http://` local dev but `true` in production (Apple's return URL is HTTPS).
- Cookie domain / subdomain mismatches when the redirect URI is on a different host than `/apple/connect`.

### Can I attach connections to something other than a `User`?

Yes. Set `APPLE_OAUTH_USER_MODEL` (or `config('apple-oauth.user_model')`) to a different Eloquent model. The `BelongsTo` relation on [`AppleConnection::user()`](API-Reference/Connection-Model#user-belongsto) resolves the model at call time.

The OAuth flow uses `$userId` as passed to [`OAuthManager::authorizationUrl()`](Oauth/Connect) — you can pass any `int|string`, so nothing forces `User` on you.

### Can I connect multiple Apple accounts per user?

Not out of the box — the `apple_connections.user_id` column has a `unique` index. Add a `provider_account` column and drop the unique index in a migration if you need this. Rare in practice for Sign in with Apple; more common in mail / calendar integrations layered on top.
