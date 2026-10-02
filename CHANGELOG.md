# ArtisanPack UI Apple OAuth Changelog

## [Unreleased]

## [1.1.0] - 2026-10-02

### Added
- **Stateless relay primitives** for OAuth brokers ([#22](https://github.com/ArtisanPack-UI/apple-oauth/issues/22)). `AppleOAuth::client( ?AppleCredentials )` returns an `AppleClient` built from runtime credentials (Services ID, Team ID, Key ID, inline `.p8` PEM, redirect URI) or the configured driver, and never touches the session or database. `authorizationUrl()` takes the caller's `state`, optional `nonce`, scopes and extra parameters, and keeps `response_mode=form_post`. `exchangeCode()` takes the expected nonce (or none) and the raw `user` form field, validates the id_token claims, and returns a `TokenResponse`. `refresh()` takes a raw refresh-token string and hands the same token back, with no `AppleConnection` required. `AppleClient::verifyState()` checks the caller's state.
- `ClientSecretGenerator::generateFor()` / `forgetFor()` mint and cache the ES256 `client_secret` for runtime `AppleCredentials`.
- `TokenResponse` gains `expiresIn`, `scopes`, `withUserId()`, `fromApple()`, `fromBroker()` and `toArray()` (the broker's wire shape). `AppleUserProfile` gains `displayName` and `fullName()`.
- **Broker client mode** (`APPLE_OAUTH_MODE=broker`). Connect, callback and refresh run through an OAuth broker using only `apple-oauth.broker.url`, `site_id`, `site_secret` and `return_url`, so the site holds no Apple client secret or `.p8` key. Signed `/authorize` links, one-time code exchange at `/token` (the id_token `sub` becomes the stable user ID), and refreshes at `/refresh`. Broker credentials can come from the `ap.apple-oauth.broker.credentials` filter. `AppleOAuth::broker()` and `AppleOAuth::usesBroker()` expose the client and mode. The broker URL must be HTTPS (plain HTTP only for `localhost`, `*.localhost`, `*.test` and loopback hosts).
- `LicenseExpiredException` (extends `TokenRefreshException`) for the broker's `402 license_expired` refresh response, carrying `getRenewUrl()`. The connection stays connected, unlike a revoked grant. `OAuthManager::isTrustedRenewUrl()` checks a `renew_url` from the broker's return before it is shown.
- `OAuthException` and `TokenRefreshException` expose the OAuth error code via `getError()`.

### Changed
- `OAuthManager::handleCallback()` and `TokenManager::refresh()` are now thin wrappers over the stateless primitives. Behavior in the default `direct` mode is unchanged.
- `TokenResponse::$userId` and `TokenResponse::$profile` are now nullable, for stateless responses and for refreshes without an id_token. Session-flow callbacks always set both. `TokenManager::store()` throws when `userId` is null.
- `TokenManager::store()` and `TokenManager::refresh()` now persist granted `scopes` when the response reports them (broker responses do; Apple's token endpoint does not).

### Documentation
- New pages: [Broker Mode](docs/broker-mode.md), [Stateless Client](docs/stateless-client.md), and API references for [`AppleClient`](docs/api-reference/apple-client.md) and [`BrokerClient`](docs/api-reference/broker-client.md).
- Updated the configuration, environment-variable, OAuth, callback, token, exception, client-secret, connection-model, testing, and FAQ docs plus the README for broker mode, the stateless primitives, `getError()` / `LicenseExpiredException`, and the new `TokenResponse` / `AppleUserProfile` members.

## [1.0.0] - 2026-09-18

### Added
- Sign in with Apple OAuth2 authorization-code flow, including `id_token` claim validation and enforced HTTPS on the token endpoint.
- Apple `client_secret` minted as an ES256 JWT with rotation support and cached generation.
- Encrypted token storage with automatic refresh when access tokens expire.
- Scope registry with the `ap.apple-oauth.scopes` filter hook so packages can contribute and reorder requested scopes.
- Configuration repository with config-file and database drivers for storing Services ID, Team ID, Key ID, and `.p8` private key material; database driver writes are atomic to close concurrent-write races.
- Optional CMS Settings bridge that lets the database driver read/write credentials through the `artisanpack-ui/cms-framework` settings surface.
- `TokenProvider` seam and `AppleOAuthManager::request()` consumer API for making authenticated calls to Apple services on behalf of a stored connection.
- `AppleOAuth` facade and helper functions covering the public surface.
- Comprehensive Pest test suite covering the OAuth callback, token refresh, and client-secret cache paths.

### Documentation
- README rewrite covering the OAuth flow, credential drivers, client-secret JWT, scope registry, and consumer API.
- Full `docs/` tree: getting started, installation, OAuth, drivers, tokens, scopes, client secret, connection model, API reference, testing, contributing, and FAQ.
- Apple Developer setup walkthrough (Services ID, `.p8` key, Team ID, Key ID, return URLs).
