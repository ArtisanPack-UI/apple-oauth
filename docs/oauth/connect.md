---
title: Connect
---

# Connect

The "start the OAuth flow" leg. You redirect an authenticated user to Apple's consent screen; the package builds the URL and stashes the anti-forgery bits in the session.

## The signature

```php
public function authorizationUrl(
    int|string $userId,
    ?array $override = null,
): string
```

- `$userId` — the app-side user id we're connecting an Apple account to. Stored in the session under the key `apple-oauth.user_id` for [`handleCallback()`](Oauth/Callback) to consume.
- `$override` — optional explicit scope list. Defaults to the [`ScopeRegistry`](Scopes) union.

Returns the fully-built authorization URL. Redirect with a plain `redirect( $url )`.

## Example wiring

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;

Route::get( '/apple/connect', function ( Request $request ) {
    $url = AppleOAuth::oauth()->authorizationUrl( $request->user()->id );

    return redirect( $url );
} )->middleware( 'auth' )->name( 'apple.connect' );
```

## Session state

`authorizationUrl()` writes three keys to the session:

| Session key | Value | Purpose |
|---|---|---|
| `apple-oauth.state` | `Str::random( 40 )` | Anti-forgery `state`. [`handleCallback()`](Oauth/Callback) compares the returned value with `hash_equals()`. |
| `apple-oauth.nonce` | `Str::random( 40 )` | Bound to the id_token via the `nonce` claim; validated on callback. |
| `apple-oauth.user_id` | `$userId` | The app-side user id the callback should attach the connection to. |

All three are `session->pull()`ed on callback, so they're consumed on read.

## Query parameters

The built URL carries:

| Parameter | Value | Notes |
|---|---|---|
| `client_id` | From the [credential driver](Drivers) | Your Services ID. |
| `redirect_uri` | From the [credential driver](Drivers) | Must be HTTPS and registered under the Services ID's Return URLs. |
| `response_type` | `code` | |
| `response_mode` | `form_post` | Apple gates the one-shot `user` payload on this — the fragment/query response modes never release it. |
| `scope` | Space-joined list | The [`ScopeRegistry`](Scopes) union or the caller's `$override`. |
| `state` | The value stashed in session | Random 40 chars. |
| `nonce` | The value stashed in session | Random 40 chars. |

## Overriding scopes for one flow

Occasionally you want a one-off flow that doesn't request the registry's full union — pass an explicit list:

```php
$url = AppleOAuth::oauth()->authorizationUrl(
    userId:   $user->id,
    override: [ 'email' ],
);
```

Apple only exposes `name` and `email` today, so this is rarely useful. If you request `email` alone, Apple will not release the one-shot display-name payload — the [`AppleUserProfile`](Connection-Model#appleuserprofile) will have `firstName === null` and `lastName === null` on the callback.

## Failure modes

### `Apple OAuth credentials are not configured.`

The active [credential driver](Drivers) has an empty `client_id` or `redirect_uri`. Check:

- `config('apple-oauth.driver')` — is it the driver you expect?
- For `config` driver: are `APPLE_OAUTH_CLIENT_ID` and `APPLE_OAUTH_REDIRECT_URI` set?
- For `database` / `cms`: has anyone `save()`d credentials yet? Did `APP_KEY` rotate?

### Session lost between `/connect` and callback

`SESSION_SAME_SITE` must be `lax` (default) or `none` for OAuth redirects to include the session cookie. `SESSION_SECURE_COOKIE` must be `false` for local `http://` dev but `true` in production. See the [FAQ](FAQ) entry on state mismatches.

### Endless redirect loop

Almost always a mismatched `redirect_uri` — the URL registered under the Services ID's Return URLs must be **exactly** what you configured in `APPLE_OAUTH_REDIRECT_URI`, including scheme, host, port, and trailing-slash agreement. Apple's error message is not always clear about this.

## After the redirect

Apple's consent screen appears. On approval, Apple `form_post`s back to your `redirect_uri` with `code`, `state`, and (on first authorization) a `user` field carrying the user's display name JSON. Details: [OAuth → Callback](Oauth/Callback).
