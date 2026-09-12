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
    | For the authorization-code flow (#2) only `client_id`, `team_id`,
    | `redirect_uri`, and `scopes` are required. The `client_secret` sent to
    | Apple during code exchange is minted on demand by the ES256 signer from
    | `team_id`, `key_id`, `client_id`, and `private_key`. Setting
    | `client_secret` here overrides the signer — useful for local testing
    | where a `.p8` key is inconvenient.
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

];
