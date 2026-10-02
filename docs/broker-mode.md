---
title: Broker Mode
---

# Broker Mode

*Since 1.1.0.*

By default (`direct` mode) your app talks to Apple with its own Services ID and `.p8` key. **Broker mode** routes connect, callback, and refresh through an ArtisanPack UI OAuth broker instead. The broker holds the Apple app credentials. Your site holds only three values: the broker's URL, its own `site_id`, and its site secret. **It never holds an Apple client secret or private key.**

Use broker mode when you ship a plugin or product to many sites and don't want every install to set up its own Apple Developer Services ID and key.

## Turning it on

```env
APPLE_OAUTH_MODE=broker

APPLE_OAUTH_BROKER_URL=https://broker.example.com
APPLE_OAUTH_BROKER_SITE_ID=site-123
APPLE_OAUTH_BROKER_SITE_SECRET="1|plain-secret-from-the-broker"
APPLE_OAUTH_BROKER_RETURN_URL=https://acme.example.com/apple/callback
```

| Config key | Env | Meaning |
|---|---|---|
| `apple-oauth.mode` | `APPLE_OAUTH_MODE` | `direct` (default) or `broker`. |
| `apple-oauth.broker.url` | `APPLE_OAUTH_BROKER_URL` | Broker base URL. **Must be HTTPS.** Plain HTTP is accepted only for local development hosts: `localhost`, `*.localhost`, `*.test`, and loopback IPs. |
| `apple-oauth.broker.site_id` | `APPLE_OAUTH_BROKER_SITE_ID` | This site's ID at the broker. |
| `apple-oauth.broker.site_secret` | `APPLE_OAUTH_BROKER_SITE_SECRET` | This site's secret, in the broker's `{id}\|{plain}` form. Sent as the bearer token on `/token` and `/refresh`. |
| `apple-oauth.broker.return_url` | `APPLE_OAUTH_BROKER_RETURN_URL` | Where the broker sends the browser back with its one-time `code`. Must be on the URL the site registered with the broker. |

In broker mode, the `client_id`, `team_id`, `key_id`, `private_key`, `client_secret`, and `redirect_uri` settings (and the [credential driver](Drivers)) are not used.

Check the mode at runtime with:

```php
AppleOAuth::usesBroker(); // true when apple-oauth.mode = broker
```

## Supplying broker credentials at runtime

A host that keeps settings in its own store (a CMS, for example) can supply the URL, site ID, and secret through the `ap.apple-oauth.broker.credentials` filter instead of env vars. It receives and returns `array{url: ?string, site_id: ?string, site_secret: ?string}`, seeded from config:

```php
addFilter( 'ap.apple-oauth.broker.credentials', function ( array $values ): array {
    return [
        'url'         => setting( 'apple.broker_url' ),
        'site_id'     => setting( 'apple.broker_site_id' ),
        'site_secret' => setting( 'apple.broker_site_secret' ),
    ];
} );
```

If any of the three values is empty after the filter runs, the broker counts as not configured. `return_url` always comes from config.

## The flow

Your connect and callback routes call the same `OAuthManager` methods as in direct mode. The mode switch happens inside them.

```
Your app                        Broker                         Apple
   │  authorizationUrl()           │                              │
   │  (state + user_id → session)  │                              │
   │── signed /authorize link ────>│── consent (with nonce) ─────>│
   │                               │<──── form_post code ─────────│
   │<── GET return_url?code&state ─│                              │
   │  handleCallback()             │                              │
   │── POST /api/v1/oauth/token ──>│  (one-time code exchange)    │
   │<── tokens + id_token ─────────│                              │
   │  tokens()->store()            │                              │
```

### Connect

`OAuthManager::authorizationUrl( $userId )` returns a **signed broker `/authorize` link**, not Apple's consent URL:

```
{broker.url}/api/v1/oauth/apple/authorize?site_id=…&state=…&return_url=…&expires=…&scopes=…&signature=…
```

- `state` is a random 40-character string. It's stored in the session together with the user ID. No nonce is stored, because the broker runs the nonce with Apple itself.
- `expires` is five minutes out. The broker accepts at most ten, so this leaves room for clock skew.
- `signature` is an HMAC-SHA256 over `"apple\n" . <sorted query string>`. The key is the SHA-256 of the plain part of the site secret (everything after the `|`).
- `scopes` is the [`ScopeRegistry`](Scopes) union, or your `$override`.

Throws [`OAuthException`](API-Reference/Exceptions#oauthexception) when the broker isn't configured or `broker.return_url` is empty.

### Callback

The broker sends the browser back to `return_url` with a **`GET`** that carries `code` and `state`. Apple's `form_post` goes to the broker, not to you, so this route needs no CSRF exemption. There's also no one-shot `user` payload: the broker relays the user's name as `account_name`.

```php
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

Route::get( '/apple/callback', function ( Request $request ) {
    if ( $request->filled( 'error' ) ) {
        // The broker may send `renew_url` with a license error. It arrives
        // on the query string, so anyone can forge it. Check it first.
        $renewUrl = $request->query( 'renew_url' );

        return redirect( '/account' )->withErrors( [
            'apple' => __( 'We could not connect your Apple account.' ),
        ] )->with( 'apple_renew_url', AppleOAuth::oauth()->isTrustedRenewUrl( $renewUrl ) ? $renewUrl : null );
    }

    try {
        $response = AppleOAuth::oauth()->handleCallback(
            code:          (string) $request->query( 'code', '' ),
            returnedState: (string) $request->query( 'state', '' ),
        );
    } catch ( OAuthException $e ) {
        Log::warning( 'Apple OAuth broker callback failed', [ 'exception' => $e ] );

        return redirect( '/account' )->withErrors( [
            'apple' => __( 'We could not complete the Sign in with Apple flow. Please try again.' ),
        ] );
    }

    AppleOAuth::tokens()->store( $response );

    return redirect( '/account' )->with( 'status', 'apple.connected' );
} )->middleware( 'auth' )->name( 'apple.callback' );
```

`handleCallback()` verifies `state` against the session exactly as in direct mode. Then it POSTs the one-time code to `{broker.url}/api/v1/oauth/token`, authenticated with the site secret. The id_token `sub` from the broker's response becomes the profile's `sub`, which is Apple's stable user ID. If the response has no usable id_token, the exchange throws, because the connection can't be tied to an Apple account without it. The broker's `account_email` and `account_name` take precedence over the id_token's claims. The name lands on `$response->profile->displayName`, and `$response->profile->fullName()` returns it.

### Refresh

`TokenManager::refresh()` and `getValidAccessToken()` POST the stored refresh token to `{broker.url}/api/v1/oauth/refresh` with `provider=apple`. Refreshes don't need Apple credentials. Error handling follows direct mode, plus one extra case:

| Broker response | Exception | Connection |
|---|---|---|
| `invalid_grant` | `TokenRefreshException`, `getError() === 'invalid_grant'` | Marked **disconnected**. The user must reconnect. |
| HTTP `402` / `license_expired` | [`LicenseExpiredException`](API-Reference/Exceptions#licenseexpiredexception) with `getRenewUrl()` | **Stays connected.** Refreshes resume once the license is renewed. |
| Anything else | `TokenRefreshException`, `getError()` = broker's code | Left as-is. Transient, so retry later. |
| Broker not configured | `TokenRefreshException`, `getError() === 'broker_not_configured'` | Left as-is. |

```php
use ArtisanPackUI\AppleOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;

try {
    $token = AppleOAuth::tokens()->getValidAccessToken( $connection );
} catch ( LicenseExpiredException $e ) {
    return back()->with( 'apple_renew_url', $e->getRenewUrl() );
} catch ( TokenRefreshException $e ) {
    // invalid_grant has already disconnected the connection.
    throw $e;
}
```

`LicenseExpiredException` extends `TokenRefreshException`, so put it first in the `catch` chain.

## Using the broker client directly

`AppleOAuth::broker()` returns the [`BrokerClient`](API-Reference/Broker-Client) built from config (or the filter). Pass [`BrokerCredentials`](API-Reference/Broker-Client#brokercredentials) to talk to a different broker:

```php
use ArtisanPackUI\AppleOAuth\Broker\BrokerCredentials;

$client = AppleOAuth::broker( new BrokerCredentials(
    url:        'https://broker.example.com',
    siteId:     'site-123',
    siteSecret: '1|plain-secret',
) );

$tokens = $client->refresh( $refreshToken );
```

## Building a broker

If you're building the broker side rather than consuming one, use the [stateless client](Stateless-Client). It runs the Apple leg from runtime credentials without touching the session or database, and `TokenResponse::toArray()` renders the exact JSON shape `BrokerClient` expects back.

---
Continue to [Stateless Client](Stateless-Client) →
