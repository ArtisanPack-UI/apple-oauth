---
title: Getting Started
---

# Getting Started

This page walks a first-time user from a fresh `composer require` to a connected Apple account making authorized API calls, linking out to deeper reference pages along the way.

## 1. Install

```bash
composer require artisanpack-ui/apple-oauth
```

The service provider (`ArtisanPackUI\AppleOAuth\AppleOAuthServiceProvider`) and the `AppleOAuth` facade auto-register. Full details: [Installation](Installation).

## 2. Migrate

```bash
php artisan migrate
```

Creates two tables:

- `apple_connections` — the per-user connection row (encrypted access + refresh + id tokens, granted scopes, expiry, status).
- `apple_configurations` — used by the [`database` driver](Drivers/Database) only.

Full column reference: [Connection Model](Connection-Model).

## 3. Apple Developer prerequisites

Sign in with Apple needs an active Apple Developer Program membership plus four values collected from Apple Developer:

| Value | What it is |
|---|---|
| Team ID | 10-character team identifier from Apple Developer → Membership. |
| Services ID | Reverse-DNS identifier registered under Identifiers → Services IDs. Becomes your OAuth `client_id`. |
| Key ID | 10-character identifier of the Sign in with Apple key you generate. |
| `.p8` private key | The signing key file Apple lets you download once. |

Full walkthrough: [Installation → Apple Developer Setup](Installation/Apple-Developer-Setup).

## 4. Wire the env

The default [`config` driver](Drivers/Config) reads credentials from environment variables:

```env
APPLE_OAUTH_CLIENT_ID=com.acme.app.web
APPLE_OAUTH_TEAM_ID=ABCDE12345
APPLE_OAUTH_KEY_ID=XXXXXXXXXX
APPLE_OAUTH_PRIVATE_KEY=/Users/you/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
APPLE_OAUTH_REDIRECT_URI=https://acme.example.com/apple/callback
```

Full env reference: [Environment Variables](Installation/Environment-Variables). If you'd rather store credentials in the database or a CMS Settings module, see [Credential Drivers](Drivers).

## 5. Mount a connect route

The package doesn't ship web routes — you own them. Both routes below **must run with the `web` middleware group** (or any middleware stack that includes `StartSession`) — `authorizationUrl()` stores `state`, `nonce`, and the app-side user id on the session, and `handleCallback()` reads them back. Declare them in `routes/web.php` (which applies the `web` group automatically), or add `->middleware( [ 'web', 'auth' ] )` explicitly if you're mounting them in `routes/api.php` or a group that omits sessions.

Redirect an authenticated user to Apple's consent URL:

```php
// routes/web.php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;

Route::get( '/apple/connect', function ( Request $request ) {
    $url = AppleOAuth::oauth()->authorizationUrl( $request->user()->id );

    return redirect( $url );
} )->middleware( 'auth' )->name( 'apple.connect' );
```

`authorizationUrl()` stashes the anti-forgery `state`, the id_token `nonce`, and the app-side user id in the session, then returns Apple's consent URL with `response_mode=form_post`. Details: [OAuth → Connect](Oauth/Connect).

## 6. Handle the callback

Apple `form_post`s the response back to your `redirect_uri`. Exempt the path from CSRF because it's a real form submission from Apple's origin — the anti-forgery guarantee comes from the `state` parameter, not Laravel's CSRF token.

**Laravel 11+** exempts paths in `bootstrap/app.php`:

```php
->withMiddleware( function ( Middleware $middleware ) {
    $middleware->validateCsrfTokens( except: [
        'apple/callback',
    ] );
} )
```

**Laravel 10** exempts paths on `App\Http\Middleware\VerifyCsrfToken` via its `$except` array.

The route (again, `routes/web.php` — `handleCallback()` needs to read the session `state` / `nonce` that `authorizationUrl()` stored):

```php
// routes/web.php
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

Route::post( '/apple/callback', function ( Request $request ) {
    try {
        $response = AppleOAuth::oauth()->handleCallback(
            code:          $request->input( 'code', '' ),
            returnedState: $request->input( 'state', '' ),
            userPayload:   $request->input( 'user' ),
        );
    } catch ( OAuthException $e ) {
        // Log the specific reason for operators; do NOT surface $e->getMessage()
        // to the end user — messages carry interpolated diagnostic values like
        // an unexpected issuer, an internal error code, or a filesystem path.
        Log::warning( 'Apple OAuth callback failed', [ 'exception' => $e ] );

        return redirect( '/' )->withErrors( [
            'apple' => __( 'We could not complete the Sign in with Apple flow. Please try again.' ),
        ] );
    }

    $connection = AppleOAuth::tokens()->store( $response );

    return redirect( '/account' )->with( 'status', 'apple.connected' );
} )->name( 'apple.callback' );
```

`handleCallback()` verifies `state`, exchanges the code with Apple's token endpoint, validates the `id_token`'s `iss` / `aud` / `exp` / `nonce` / `sub` claims, and returns a [`TokenResponse`](API-Reference/OAuth-Manager#token-response). `AppleOAuth::tokens()->store( $response )` persists an encrypted [`AppleConnection`](Connection-Model) row. Details: [OAuth → Callback](Oauth/Callback).

> Apple only releases the display name in the one-shot `user` form field, and only on the initial authorization for a given Services ID. Persist it on the first callback — subsequent authorizations never re-emit it.

## 7. Make an authorized API call

Downstream code resolves a valid access token through the [`AppleOAuthManager`](API-Reference/Apple-OAuth-Manager) — it refreshes transparently and returns a Laravel `PendingRequest` pre-authorized with the token:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

$connection = $user->appleConnection; // however you load it

$response = AppleOAuth::manager()
    ->request( $connection )
    ->acceptJson()
    ->get( 'https://caldav.icloud.com/...' );
```

For code that only needs the raw token string:

```php
$token = AppleOAuth::manager()->tokens()->accessTokenFor( $connection );
```

Details: [Tokens](Tokens), [API Reference → TokenProvider](API-Reference/Token-Provider).

## Next steps

- Add scopes from your own service package: [Scopes](Scopes).
- Understand what `getValidAccessToken()` does when the token is expired: [Tokens](Tokens).
- Rotate your `.p8` key: [Client-Secret JWT](Client-Secret).
- Testing patterns: [Testing](Testing).

---
Continue to [Installation](Installation) →
