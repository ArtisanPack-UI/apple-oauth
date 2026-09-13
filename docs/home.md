---
title: Home
---

# ArtisanPack UI Apple OAuth Documentation

Welcome to the documentation for **ArtisanPack UI Apple OAuth** — the shared Sign in with Apple broker that powers every ArtisanPack UI Apple service integration.

Downstream packages (starting with calendar sync for [`artisanpack-ui/bookings`](https://github.com/ArtisanPack-UI/bookings)) sit on top of this package — they resolve a valid access token from the shared [`TokenProvider`](API-Reference/Token-Provider) seam and make their Apple API calls, without ever touching the OAuth handshake, client-secret JWT signer, or refresh flow themselves.

Use the navigation below to explore topics. Links use the GitLab wiki page style, so you can jump between pages like [Getting Started](Getting-Started) or [OAuth Flow](Oauth).

- [Getting Started](Getting-Started)
- [Installation](Installation)
- [Credential Drivers](Drivers)
- [OAuth Flow](Oauth)
- [Scopes](Scopes)
- [Tokens](Tokens)
- [Client-Secret JWT](Client-Secret)
- [Connection Model](Connection-Model)
- [API Reference](API-Reference)
- [Testing](Testing)
- [FAQ](FAQ)
- [Contributing](Contributing)

If you're new here, start with [Getting Started](Getting-Started).

## What this package does

`artisanpack-ui/apple-oauth` owns the shared plumbing that every ArtisanPack UI Sign in with Apple integration builds on. It provides:

- **OAuth2 authorization-code flow** — builds the consent URL, verifies the returned `state` / `nonce` / `id_token` claims, exchanges the authorization code for tokens, and captures the one-shot `user` payload Apple only releases on first authorization.
- **ES256 `client_secret` JWT signing** — Apple requires the OAuth `client_secret` to be a short-lived JWT signed with the developer's P-256 `.p8` key. The package mints, caches, and rotates one on demand. See [Client-Secret JWT](Client-Secret).
- **Encrypted token storage** on a per-user `apple_connections` model, with transparent refresh via the [token manager](Tokens) and automatic disconnection on `invalid_grant`.
- A **scope registry** that lets any installed service package contribute scopes through the `ap.apple-oauth.scopes` filter hook.
- **Credential storage drivers** ([config, database, or CMS](Drivers)) so credentials can live wherever a project already stores its secrets.

## What this package does not do

- It does **not** call Apple APIs. That is the responsibility of downstream service packages (calendar sync, mail, …).
- It does **not** ship any web routes. Because Apple requires `response_mode=form_post` for scopes that release the one-shot `user` payload, the callback needs to be a `POST` your app owns — see [OAuth → Callback](Oauth/Callback) for the wiring.
- It does **not** ship a Livewire / React / Vue connection-management UI. Apple's flow is simpler than Google's (no incremental consent, no per-service scope UX), so per-account connect / disconnect is a two-route affair in your app.
- It does **not** perform remote token revocation on disconnect — the flow marks the local connection disconnected. If you need to revoke with Apple, POST to `https://appleid.apple.com/auth/revoke` from your own code with the full form body Apple requires: `client_id` (your Services ID), `client_secret` (a client-secret JWT — either pre-minted, or `AppleOAuth::clientSecret()->generate()`), `token` (the stored refresh token or access token), and `token_type_hint=refresh_token` (or `access_token`, matching what you pass in `token`). See [OAuth → Disconnect](Oauth/Disconnect) for a full example.
