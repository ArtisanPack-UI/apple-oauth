---
title: OAuth Flow
---

# OAuth Flow

The package implements the **OAuth 2.0 Authorization Code flow** end-to-end for Sign in with Apple. Users visit a "Connect Apple" link in your app, get redirected to Apple's consent screen, and Apple `form_post`s the response back to a callback route you own; on success your app persists an encrypted [`AppleConnection`](Connection-Model) row and continues.

This page walks through each leg. See the sub-pages for deeper coverage.

## No built-in routes

Unlike some OAuth packages, `artisanpack-ui/apple-oauth` does **not** ship a controller or routes. Two reasons:

1. Apple gates the one-shot `user` payload on `response_mode=form_post` — the callback is a `POST` your app owns, so you can attach auth middleware, layouts, and post-connect redirects however you like.
2. Apple's flow doesn't need incremental consent (only `name` + `email` are exposed), so a package-shipped `/reauthorize` route would have nothing to do.

You wire two routes and delegate to the manager. See [Getting Started](Getting-Started) for a working example.

## The flow, end to end

```
┌──────────────┐         ┌──────────────────┐         ┌─────────────┐
│  Your app    │         │  Apple package   │         │    Apple    │
└──────┬───────┘         └────────┬─────────┘         └──────┬──────┘
       │                          │                          │
       │  User clicks "Connect"   │                          │
       │─────────────────────────>│  authorizationUrl()      │
       │                          │                          │
       │                          │  Build authorize URL     │
       │                          │  Store state + nonce +   │
       │                          │  user_id in session      │
       │<─────────────────────────│                          │
       │  redirect to Apple ────────────────────────────────>│  User approves scopes
       │                                                     │
       │  Apple form-POSTs `code`, `state`, `user`, `id_token` back to your callback
       │<────────────────────────────────────────────────────│
       │                          │                          │
       │  handleCallback()───────>│                          │
       │                          │  Verify state            │
       │                          │  POST /auth/token────────>│
       │                          │<─────────────────────────│  { access_token, refresh_token, id_token, ... }
       │                          │                          │
       │                          │  Validate id_token       │
       │                          │  claims                  │
       │                          │  Merge one-shot `user`   │
       │                          │  payload                 │
       │<─────────────────────────│  TokenResponse           │
       │                                                     │
       │  tokens()->store($response)                         │
       │                                                     │
```

## Connect

You send an authenticated user to a route that calls [`OAuthManager::authorizationUrl()`](API-Reference/OAuth-Manager#authorizationurl) and redirects to the returned URL.

`authorizationUrl()`:

1. Reads `client_id` and `redirect_uri` from the configured [credential driver](Drivers). Throws `OAuthException("Apple OAuth credentials are not configured.")` if either is missing.
2. Reads the scope list — the [`ScopeRegistry`](API-Reference/Scope-Registry) union by default, or an explicit `$override` array.
3. Generates a random 40-char `state` and 40-char `nonce`, stashes them plus the app-side user id in the session.
4. Returns the Apple authorize URL with:
   - `client_id`, `redirect_uri`, `scope`, `state`, `nonce`
   - `response_type=code`
   - **`response_mode=form_post`** — Apple only posts the `user` payload back once (on the first authorization) and refuses to release it over the fragment or query response modes.

Details: [OAuth → Connect](Oauth/Connect).

## Callback

Apple `form_post`s the response back to your `redirect_uri`. Because it's a real form submission from Apple's origin, you exempt the callback path from CSRF — the anti-forgery guarantee comes from the `state` parameter the package verifies against the session, not Laravel's CSRF token.

Your callback route calls [`OAuthManager::handleCallback( $code, $state, $userPayload )`](API-Reference/OAuth-Manager#handlecallback). The manager:

1. Pulls the stored `state`, `nonce`, and `user_id` from the session (via `$session->pull()`, so they're consumed). Any missing value throws.
2. Compares the returned `state` against the stored one with `hash_equals()` to defeat timing attacks. Mismatch = `OAuthException("OAuth state mismatch; possible CSRF attempt.")`.
3. Refuses to hit an `http://` token endpoint — if `apple-oauth.endpoints.token` isn't HTTPS, throws `OAuthException("Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.")`.
4. Uses the pre-minted `client_secret` if one is configured; otherwise mints a fresh ES256 JWT via [`ClientSecretGenerator`](Client-Secret).
5. POSTs to Apple's `/auth/token` with `grant_type=authorization_code`.
6. Extracts `access_token`, `id_token`, `refresh_token`, `token_type`, `expires_in` from the response — missing `access_token` or `id_token` throws.
7. Decodes the id_token payload (base64url of the middle segment) and validates the `iss`, `aud`, `exp`, `nonce`, and `sub` claims. **Signature verification is deferred** — identity trust rests on the TLS-terminated server-to-server exchange plus these claim checks.
8. Merges the id_token's `sub` / `email` with the one-shot `user` payload (name-only from the browser POST) into an [`AppleUserProfile`](Connection-Model#appleuserprofile) — the id_token email is canonical, and the `user` payload is only trusted for the display name.
9. Returns a [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse).

You then call [`TokenManager::store()`](Tokens) to persist the response as an encrypted `apple_connections` row.

Details: [OAuth → Callback](Oauth/Callback).

## The one-shot `user` payload

Apple only releases the display name on the **initial** authorization for a given Services ID. Subsequent authorizations for the same user never re-emit the `user` field, even after a full sign-out. Persist the name on the first callback — [`TokenManager::store()`](Tokens) preserves the existing `apple_user_id` / `email` on re-authorizations, so you don't lose them if the second-round `TokenResponse` arrives with a partial profile.

Details: [OAuth → Callback](Oauth/Callback) and [Connection Model](Connection-Model).

## Disconnect

There's no built-in disconnect route — Apple's flow doesn't require one, and how you surface "disconnect from Apple" is app-specific UX.

To disconnect a user locally:

```php
$user->appleConnection->markDisconnected( 'Disconnected by user.' );
```

This sets `status = 'disconnected'`, records a reason, and leaves the tokens on the row. [`AppleOAuthManager::request()`](API-Reference/Apple-OAuth-Manager) throws `TokenRefreshException("Apple connection is disconnected.")` immediately for disconnected connections without hitting Apple.

**Local-only** — this does not hit Apple's revoke endpoint. If you need remote revocation, POST to `https://appleid.apple.com/auth/revoke` before calling `markDisconnected()`. Details: [OAuth → Disconnect](Oauth/Disconnect).

## Exceptions

The OAuth manager throws two exception types:

| Exception | Thrown by | When |
|---|---|---|
| [`OAuthException`](API-Reference/Exceptions#oauthexception) | `authorizationUrl()`, `handleCallback()` | Missing credentials, HTTPS violation on token endpoint, state mismatch, missing session context, code-exchange failure, missing required token fields, id_token claim mismatch, malformed id_token. |
| [`TokenRefreshException`](API-Reference/Exceptions#tokenrefreshexception) | [`TokenManager`](Tokens) | Disconnected connection, missing refresh token, HTTPS violation on refresh, refresh failure, missing `access_token` in refresh response. |

## Deeper topics

- [Connect](Oauth/Connect) — building the authorize URL, session state/nonce, `response_mode=form_post`.
- [Callback](Oauth/Callback) — code exchange, id_token claim validation, one-shot `user` payload, refresh-token preservation.
- [Disconnect](Oauth/Disconnect) — `markDisconnected()`, reconnect flow, local vs. remote revocation.

---
Continue to [Scopes](Scopes) →
