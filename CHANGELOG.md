# ArtisanPack UI Apple OAuth Changelog

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
