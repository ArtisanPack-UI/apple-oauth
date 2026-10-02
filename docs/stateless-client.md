---
title: Stateless Client
---

# Stateless Client

*Since 1.1.0.*

[`AppleClient`](API-Reference/Apple-Client) is the stateless Sign in with Apple client that every Apple call in the package goes through. It never touches the session or the database. You supply the credentials, `state`, `nonce`, and scopes, and you get a [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse) back.

In direct mode, [`OAuthManager`](Oauth) and [`TokenManager`](Tokens) are thin wrappers that add session handling and persistence around it. Use it directly when that wrapping gets in your way, mainly when you're **building an OAuth broker** that relays Sign in with Apple for apps it doesn't configure globally.

## Getting a client

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\AppleOAuth\OAuth\AppleCredentials;

// From the configured credential driver:
$client = AppleOAuth::client();

// From credentials supplied at runtime (e.g. decrypted from your own database):
$client = AppleOAuth::client( new AppleCredentials(
    clientId:    'com.acme.app.web',
    teamId:      'ABCDE12345',
    keyId:       'XXXXXXXXXX',
    privateKey:  $pemString,          // inline PEM, or a path to the .p8
    redirectUri: 'https://broker.example.com/oauth/apple/callback',
) );
```

The client takes its endpoints from `apple-oauth.endpoints`. Set `clientSecret:` on `AppleCredentials` to send a pre-minted JWT instead of signing one. Otherwise the [`ClientSecretGenerator`](Client-Secret) mints and caches an ES256 JWT per (team, key, client) set through `generateFor()`.

## 1. Build the consent URL

```php
use Illuminate\Support\Str;

$state = Str::random( 40 );
$nonce = Str::random( 40 );

// Keep $state and $nonce wherever your flow keeps them.
$url = $client->authorizationUrl( $state, [ 'name', 'email' ], $nonce );
```

- `response_mode=form_post` is always set. Apple refuses to release the one-shot `user` payload over the query or fragment modes.
- `$nonce` is optional. Omit it to send none.
- A fourth `$parameters` array adds or overrides query parameters.

## 2. Verify state and exchange the code

Apple form-POSTs `code`, `state`, and (on first authorization only) `user` to the redirect URI:

```php
use ArtisanPackUI\AppleOAuth\OAuth\AppleClient;

AppleClient::verifyState( $expectedState, $request->input( 'state', '' ) );

$tokens = $client->exchangeCode(
    code:        $request->input( 'code', '' ),
    nonce:       $expectedNonce,           // null if you sent none
    userPayload: $request->input( 'user' ),
);
```

`verifyState()` is a timing-safe compare that throws `OAuthException` on mismatch or an empty expected value. `exchangeCode()` doesn't check state itself.

`exchangeCode()` POSTs to Apple's token endpoint (HTTPS only) and validates the id_token's `iss`, `aud`, `exp`, and `sub` claims. When you pass a nonce, it validates `nonce` too. The returned `TokenResponse` has `userId === null`, so call `->withUserId( $id )` before passing it to `TokenManager::store()`.

## 3. Refresh

```php
$tokens = $client->refresh( $refreshToken );
```

`refresh()` takes the raw refresh-token string and needs no `AppleConnection`. Apple doesn't return a refresh token on refresh, so the response hands back the one you passed in (or a rotated one, if Apple ever sends it). A revoked grant throws `TokenRefreshException` with `getError() === 'invalid_grant'`.

## Relaying to a site

`TokenResponse::toArray()` renders the broker's site-facing wire shape. A broker can return it as its JSON response verbatim, and the site's [`BrokerClient`](API-Reference/Broker-Client) reads it back with `TokenResponse::fromBroker()`:

```php
return response()->json( $tokens->toArray() );
// {
//   "token_type": "Bearer",
//   "access_token": "…",
//   "refresh_token": "…",
//   "expires_in": 3600,
//   "scopes": [],
//   "account_email": "ada@example.com",
//   "account_name": "Ada Lovelace",
//   "id_token": "…"
// }
```

## What it doesn't do

- **No session.** Generate, store, and check `state` and `nonce` yourself.
- **No persistence.** Nothing is written to `apple_connections`.
- **No id_token signature verification.** The same claim checks as the session flow apply. See [OAuth → Callback](Oauth/Callback).

---
Continue to [Scopes](Scopes) →
