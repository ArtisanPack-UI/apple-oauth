<?php

/**
 * Apple OAuth package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | Client credentials
    |--------------------------------------------------------------------------
    |
    | The Services ID (client_id), the Apple Developer team_id, and the key_id
    | of the private key used to sign the client-secret JWT. The private key
    | itself lives either inline as PEM or as a filesystem path, and is used
    | by the client-secret signer (see issue #3 for ES256 JWT generation).
    |
    | Two credential modes are supported for the code-exchange step:
    |
    | 1. Generated (production): the ES256 signer mints the `client_secret`
    |    JWT on demand. Requires `client_id`, `team_id`, `key_id`, and
    |    `private_key` (plus `redirect_uri` and `scopes` for the flow itself).
    |
    | 2. Static override (local testing): set `client_secret` to a
    |    pre-minted JWT string. The signer is bypassed entirely and
    |    `team_id`, `key_id`, and `private_key` are not required.
    |
    | Security note on `private_key`: prefer an absolute filesystem path to
    | the `.p8` file over inline PEM. `php artisan config:cache` freezes
    | `env()` reads into `bootstrap/cache/config.php`, so an inline PEM will
    | be persisted in cleartext inside the cache file. A filesystem path
    | avoids that: only the path string is cached, and the key bytes stay
    | in whatever protected location you point at (e.g.
    | `~/.config/artisanpack/AuthKey_XXXXXXXXXX.p8`, mode 600).
    |
    */

    'client_id'     => env( 'APPLE_OAUTH_CLIENT_ID' ),
    'team_id'       => env( 'APPLE_OAUTH_TEAM_ID' ),
    'key_id'        => env( 'APPLE_OAUTH_KEY_ID' ),
    'private_key'   => env( 'APPLE_OAUTH_PRIVATE_KEY' ),
    'client_secret' => env( 'APPLE_OAUTH_CLIENT_SECRET' ),

    /*
    |--------------------------------------------------------------------------
    | Client-secret JWT lifetime
    |--------------------------------------------------------------------------
    |
    | Apple caps `client_secret` JWTs at six months. Shorter windows keep the
    | blast radius of a leaked JWT small; the generator caches within its own
    | validity window (minus `client_secret_leeway` seconds) and rotates
    | transparently on the next call once the cache expires.
    |
    */

    'client_secret_ttl'    => (int) env( 'APPLE_OAUTH_CLIENT_SECRET_TTL', 3600 ),
    'client_secret_leeway' => (int) env( 'APPLE_OAUTH_CLIENT_SECRET_LEEWAY', 30 ),

    /*
    |--------------------------------------------------------------------------
    | Redirect URI
    |--------------------------------------------------------------------------
    |
    | Absolute URL Apple will POST the authorization response back to. Must
    | match one of the return URLs registered with the Services ID.
    |
    */

    'redirect_uri' => env( 'APPLE_OAUTH_REDIRECT_URI' ),

    /*
    |--------------------------------------------------------------------------
    | Default scopes
    |--------------------------------------------------------------------------
    |
    | List of scopes Apple should prompt for on first authorization. Apple
    | currently supports `name` and `email`.
    |
    */

    'scopes' => [ 'name', 'email' ],

    /*
    |--------------------------------------------------------------------------
    | Apple endpoints
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'authorize' => 'https://appleid.apple.com/auth/authorize',
        'token'     => 'https://appleid.apple.com/auth/token',
    ],

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class name of the app-side user model an
    | `apple_connections` row belongs to. Consumer apps that use a non-default
    | user model (or a User model located outside `App\Models\`) should point
    | this at their own class.
    |
    */

    'user_model' => env( 'APPLE_OAUTH_USER_MODEL', 'App\\Models\\User' ),

];
