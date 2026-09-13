# ArtisanPack UI — Apple OAuth

Shared Sign in with Apple OAuth2 broker for ArtisanPack UI's Apple service integrations. Downstream packages (starting with calendar sync for [`artisanpack-ui/bookings`](https://github.com/ArtisanPack-UI/bookings)) sit on top of this package rather than re-implementing the OAuth handshake, client-secret signing, and token refresh themselves.

- [What this package does](#what-this-package-does)
- [Installation](#installation)
- [Apple Developer setup](#apple-developer-setup)
- [Credential storage: config vs. database vs. CMS](#credential-storage)
- [Connecting a user](#connecting-a-user)
- [Handling the callback](#handling-the-callback)
- [Registering scopes from a service package](#registering-scopes-from-a-service-package)
- [Making API calls](#making-api-calls)
- [Client-secret JWT](#client-secret-jwt)
- [Configuration reference](#configuration-reference)
- [Contributing](#contributing)

## What this package does

`artisanpack-ui/apple-oauth` is the shared plumbing that every ArtisanPack UI Sign in with Apple integration builds on. It owns:

- The **OAuth2 authorization-code flow** — building the consent URL, verifying the returned `state` / `nonce` / `id_token` claims, exchanging the authorization code for tokens, and capturing the one-shot `user` payload Apple only releases on first authorization.
- **ES256 `client_secret` JWT signing.** Apple requires the OAuth `client_secret` to be a short-lived JWT signed with the developer's P-256 `.p8` key. The package mints, caches, and rotates one on demand.
- **Encrypted token storage** on a per-user `apple_connections` model, with transparent refresh against `/auth/token` and automatic disconnection on `invalid_grant`.
- A **scope registry** so any installed service package can contribute scopes to the single consent screen.
- **Credential storage drivers** (config, database, or CMS Settings) so credentials can live wherever a project already keeps its secrets.

Service packages retrieve a currently-valid access token through the `TokenProvider` seam and never touch the OAuth internals.

## Installation

Install via Composer:

```bash
composer require artisanpack-ui/apple-oauth
```

The service provider and `AppleOAuth` facade are auto-discovered.

Run migrations to create the `apple_connections` and `apple_configurations` tables:

```bash
php artisan migrate
```

The migrations register automatically; publish only when you need to edit them:

```bash
php artisan vendor:publish --tag=apple-oauth-migrations
```

Optionally publish the config file to override credential storage, endpoints, or the user model:

```bash
php artisan vendor:publish --tag=apple-oauth-config
```

### Optional peer packages

| Package | What it enables |
|---|---|
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | The `cms` credential driver — stores credentials via the CMS Settings module. |

The base package boots and works without it.

## Apple Developer setup

Sign in with Apple requires a few Apple Developer artifacts before the OAuth flow can run. You need an active membership in the [Apple Developer Program](https://developer.apple.com/programs/) — Sign in with Apple is not available to free accounts.

You will finish this walkthrough with four values that go into your credential store: a **Services ID** (the OAuth `client_id`), a **Team ID**, a **Key ID**, and the contents of a `.p8` **private key** file.

### 1. Find your Team ID

Open [Apple Developer → Membership](https://developer.apple.com/account/#!/membership). Copy the 10-character **Team ID** shown near the top — this becomes `APPLE_OAUTH_TEAM_ID`.

### 2. Enable Sign in with Apple on an App ID

Sign in with Apple keys are always scoped to an App ID first, and the Services ID you'll create in the next step will be associated with the same App ID.

1. Open [Certificates, Identifiers & Profiles → Identifiers](https://developer.apple.com/account/resources/identifiers/list).
2. Either select an existing **App ID** (type: App IDs) or click **+** to register a new one.
3. In the App ID's capabilities list, tick **Sign in with Apple** and save.

### 3. Create the Services ID (this becomes your `client_id`)

1. Still in **Identifiers**, filter the list to **Services IDs** and click **+**.
2. Choose **Services IDs**, click **Continue**, and give it:
    - **Description**: a human-readable name (e.g. "Acme App — Web Sign in").
    - **Identifier**: a reverse-DNS identifier (e.g. `com.acme.app.web`). This string is your `APPLE_OAUTH_CLIENT_ID`.
3. Continue and register.
4. Open the newly-created Services ID and:
    - Tick **Sign in with Apple** to enable the capability.
    - Click **Configure** next to it.
    - Under **Primary App ID**, select the App ID from step 2.
    - Under **Domains and Subdomains**, add the domain of the app that will host the redirect URI (e.g. `app.acme.test`, `acme.example.com`). Apple validates these; localhost and `.test` domains work in development but must resolve.
    - Under **Return URLs**, add the absolute callback URL your app will handle, e.g. `https://app.acme.test/apple/callback`. This is your `APPLE_OAUTH_REDIRECT_URI`.
    - Save.

> Apple will not accept an `http://` return URL — the redirect must be HTTPS. In development, use a tool like Laravel Herd's per-site TLS or `valet secure`.

### 4. Create the Sign in with Apple key (`.p8`)

1. Open [Keys](https://developer.apple.com/account/resources/authkeys/list) and click **+**.
2. Give the key a name, tick **Sign in with Apple**, and click **Configure** next to it.
3. Under **Primary App ID**, choose the App ID from step 2.
4. Continue → Register → Download.
5. Apple emits an **AuthKey_XXXXXXXXXX.p8** file. Copy the **Key ID** (the `XXXXXXXXXX` portion) — this is `APPLE_OAUTH_KEY_ID`.

> **Apple only lets you download the `.p8` once.** Store it somewhere backed up. If you lose it you must revoke the key and generate a new one.

Move the `.p8` file somewhere your application can read but is otherwise protected:

```bash
mkdir -p ~/.config/artisanpack
mv ~/Downloads/AuthKey_XXXXXXXXXX.p8 ~/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
chmod 600 ~/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
```

> The path above assumes a development machine where PHP runs as your user. In production, place the `.p8` at a path readable by the web-server user (e.g. `/etc/artisanpack/AuthKey_XXXXXXXXXX.p8` owned by `www-data` / `_www`, mode 600) — or use the `database` / `cms` credential driver so the key material is stored encrypted in your app's data store instead.

### 5. Wire the four values into your app

Point the package at the values you just collected. The default `config` driver reads them from environment variables:

```env
APPLE_OAUTH_CLIENT_ID=com.acme.app.web
APPLE_OAUTH_TEAM_ID=ABCDE12345
APPLE_OAUTH_KEY_ID=XXXXXXXXXX
APPLE_OAUTH_PRIVATE_KEY=/Users/you/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
APPLE_OAUTH_REDIRECT_URI=https://app.acme.test/apple/callback
```

`APPLE_OAUTH_PRIVATE_KEY` accepts either an absolute filesystem path to a `.p8` file (recommended) or an inline PEM string. Prefer the path form: `php artisan config:cache` freezes `env()` reads into `bootstrap/cache/config.php`, so an inline PEM would be persisted in cleartext inside the cache file. A path stores only the string; the key bytes stay wherever you point at.

## Credential storage

`artisanpack-ui/apple-oauth` supports three credential storage drivers. Choose one by setting `APPLE_OAUTH_DRIVER` (or `config('apple-oauth.driver')`). OAuth tokens are never stored here — they live on the `apple_connections` table keyed by user.

### `config` (default)

Reads credentials from `config/apple-oauth.php` / `.env`. Best for single-tenant apps where credentials belong in the deploy pipeline. The config driver is read-only; calling `save()` on it throws.

### `database`

Stores credentials in the `apple_configurations` table. `private_key` and `client_secret` are encrypted with Laravel's `Encrypter` (using `APP_KEY`) before they are written. The table is constrained to a single row via a `singleton` sentinel column, so `save()` performs an atomic upsert without a read-then-write race under concurrent writers.

```env
APPLE_OAUTH_DRIVER=database
```

```php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

app( ConfigurationRepository::class )->save( [
    'client_id'    => 'com.acme.app.web',
    'team_id'      => 'ABCDE12345',
    'key_id'       => 'XXXXXXXXXX',
    'private_key'  => file_get_contents( '/path/to/AuthKey_XXXXXXXXXX.p8' ),
    'redirect_uri' => 'https://app.acme.test/apple/callback',
] );
```

If you rotate `APP_KEY` without re-encrypting the stored key material, the driver treats the row as unconfigured.

### `cms` (optional, requires `artisanpack-ui/cms-framework`)

Delegates get/set to the CMS framework's Settings module so credentials live alongside every other site-level setting:

```env
APPLE_OAUTH_DRIVER=cms
```

The private key and client secret are encrypted at rest — the service provider registers sanitize callbacks with the CMS framework so both this driver's writes and any save through the Settings UI persist the same encrypted ciphertext. If the CMS framework is not installed, the driver's setting keys simply are not registered and reads report as "not configured" — never a hard boot error.

### Local-testing override: a pre-minted `client_secret`

For local testing you can bypass the ES256 signer entirely by setting a pre-minted `client_secret` JWT string:

```env
APPLE_OAUTH_CLIENT_SECRET=eyJhbGciOi...
```

When set, `team_id`, `key_id`, and `private_key` are no longer required for the code exchange — the package sends the string you provided as the `client_secret` form field verbatim.

## Connecting a user

`artisanpack-ui/apple-oauth` does not ship the two web routes the flow needs — you own the connect and callback routes so you can attach them to whatever auth middleware and layout your app already uses. The package hands you the URL to redirect to and the class that redeems the code Apple returns.

Send the authenticated user to the authorization URL:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;

Route::get( '/apple/connect', function ( Request $request ) {
    $url = AppleOAuth::oauth()->authorizationUrl( $request->user()->id );

    return redirect( $url );
} )->middleware( 'auth' )->name( 'apple.connect' );
```

`authorizationUrl()` stashes the anti-forgery `state`, the id_token `nonce`, and the app-side user id in the session, then returns Apple's consent URL with `response_mode=form_post` — Apple gates the one-shot `user` payload on that response mode, so it is not configurable.

## Handling the callback

Apple `form_post`s the response back to your `redirect_uri`. Because that is a real form submission from Apple's origin — not a request from your own site — you have to exempt the callback path from Laravel's CSRF middleware; the anti-forgery guarantee comes from the `state` parameter the package verifies against the session, not from Laravel's CSRF token.

**Laravel 11+** exempts paths in `bootstrap/app.php`:

```php
->withMiddleware( function ( Middleware $middleware ) {
    $middleware->validateCsrfTokens( except: [
        'apple/callback',
    ] );
} )
```

**Laravel 10** exempts paths on `App\Http\Middleware\VerifyCsrfToken` via its `$except` array.

The route itself:

```php
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use Illuminate\Http\Request;

Route::post( '/apple/callback', function ( Request $request ) {
    try {
        $response = AppleOAuth::oauth()->handleCallback(
            code:          $request->input( 'code', '' ),
            returnedState: $request->input( 'state', '' ),
            userPayload:   $request->input( 'user' ),
        );
    } catch ( OAuthException $e ) {
        return redirect( '/' )->withErrors( [ 'apple' => $e->getMessage() ] );
    }

    $connection = AppleOAuth::tokens()->store( $response );

    return redirect( '/account' )->with( 'status', 'apple.connected' );
} )->name( 'apple.callback' );
```

`handleCallback()` verifies the returned `state` against the session, exchanges the code with Apple's token endpoint, validates the `id_token`'s `iss` / `aud` / `exp` / `nonce` / `sub` claims, and returns a `TokenResponse` value object carrying:

- `accessToken`, `refreshToken`, `idToken`, `tokenType`, `expiresAt`
- `profile` — an `AppleUserProfile` with `sub`, `email`, and, only on the first authorization, `firstName` / `lastName`

`AppleOAuth::tokens()->store( $response )` persists the `TokenResponse` as an encrypted `apple_connections` row and returns the `AppleConnection` model.

> Apple only releases the display name in the one-shot `user` form field, and only on the initial authorization for a given Services ID. Persist it on the first callback — subsequent authorizations never re-emit it.

## Registering scopes from a service package

Sign in with Apple exposes only two scopes today — `name` and `email` — and the package always requests both, because Apple gates the one-shot `user` payload on requesting them. Additional scopes reserved for future Apple-issued grants (or a bring-your-own baseline for a downstream broker) can be contributed by dependent packages via the `ap.apple-oauth.scopes` filter hook from [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks):

```php
use ArtisanPackUI\Hooks\Facades\Filter;

// In your service provider's boot() method:
Filter::add( 'ap.apple-oauth.scopes', function ( array $scopes ): array {
    $scopes[] = 'my.custom.scope';
    return $scopes;
} );
```

Applications that need to add a scope without a service provider can call `AppleOAuth::scopes()->register( $scope )` at runtime.

## Making API calls

Downstream service packages authorize their Apple API calls through the `AppleOAuthManager` — a thin wrapper that resolves the connection's current access token (refreshing transparently when the stored token has expired) and hands back a Laravel `PendingRequest` pre-authorized with it:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

$connection = $user->appleConnection; // however you load it

$response = AppleOAuth::manager()
    ->request( $connection )
    ->acceptJson()
    ->get( 'https://caldav.icloud.com/...' );
```

For code that only needs the raw token string (e.g. to hand to a third-party SDK owning its own HTTP client):

```php
$token = AppleOAuth::manager()->tokens()->accessTokenFor( $connection );
```

`accessTokenFor()` is the stable seam. Downstream packages should type-hint the `TokenProvider` contract rather than the concrete manager so tests can rebind it to a fake:

```php
use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

public function __construct( protected TokenProvider $apple ) {}

public function fetch( AppleConnection $connection ): array
{
    $token = $this->apple->accessTokenFor( $connection );
    // ...
}
```

When Apple rejects a refresh with `invalid_grant`, the connection is marked disconnected with a stored reason and a `TokenRefreshException` is thrown. Connections in the `disconnected` state throw immediately from `accessTokenFor()` without hitting Apple.

## Client-secret JWT

Apple requires the OAuth `client_secret` to be a JWT signed with your `.p8` private key (P-256 / ES256). `ClientSecretGenerator` handles this transparently:

- It mints a fresh JWT on demand from `team_id`, `key_id`, `client_id`, and `private_key`.
- It caches the JWT inside its own validity window (minus `client_secret_leeway` seconds) so a cache-warmed JWT is never handed out on the edge of expiry.
- It silently rotates the JWT when the cache expires.

Apple caps `client_secret` JWTs at six months (`15_777_000` seconds); the generator clamps `client_secret_ttl` to that ceiling. Only P-256 keys (`prime256v1` / `secp256r1`) are accepted — a P-384 key would produce a truncated (invalid) signature and is rejected up front.

To discard the cached JWT (e.g. after rotating the `.p8` key):

```php
AppleOAuth::clientSecret()->forget();
```

## Configuration reference

Key options in `config/apple-oauth.php`:

| Key | Default | Meaning |
|---|---|---|
| `driver` | `config` | Credential driver: `config`, `database`, or `cms`. |
| `client_id` | `env('APPLE_OAUTH_CLIENT_ID')` | The Services ID from Apple Developer. |
| `team_id` | `env('APPLE_OAUTH_TEAM_ID')` | The 10-character Apple Developer team ID. |
| `key_id` | `env('APPLE_OAUTH_KEY_ID')` | The 10-character Key ID of the `.p8` used to sign the client secret. |
| `private_key` | `env('APPLE_OAUTH_PRIVATE_KEY')` | Absolute path to the `.p8` file, or inline PEM. |
| `client_secret` | `env('APPLE_OAUTH_CLIENT_SECRET')` | Pre-minted JWT string; bypasses the ES256 signer when set. |
| `redirect_uri` | `env('APPLE_OAUTH_REDIRECT_URI')` | Absolute HTTPS URL registered with the Services ID's Return URLs. |
| `client_secret_ttl` | `3600` | Lifetime of minted `client_secret` JWTs, in seconds. Clamped to Apple's six-month max. |
| `client_secret_leeway` | `30` | Seconds subtracted from `client_secret_ttl` when caching, so a warmed JWT is never handed out on the edge of expiry. |
| `endpoints.authorize` | `https://appleid.apple.com/auth/authorize` | Authorization endpoint; overridable for testing. |
| `endpoints.token` | `https://appleid.apple.com/auth/token` | Token endpoint; overridable for testing. Must be HTTPS. |
| `user_model` | `App\Models\User` | The user model an `apple_connections` row belongs to. |

## Contributing

Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.
