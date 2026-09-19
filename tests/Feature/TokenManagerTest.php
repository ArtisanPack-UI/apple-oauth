<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use ArtisanPackUI\AppleOAuth\OAuth\AppleUserProfile;
use ArtisanPackUI\AppleOAuth\OAuth\TokenResponse;
use ArtisanPackUI\AppleOAuth\Tokens\TokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.team_id', 'TEAM1234' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/oauth/apple/callback' );
    config()->set( 'apple-oauth.client_secret', 'signed-jwt-placeholder' );
} );

function tokenResponse( array $overrides = [] ): TokenResponse
{
    $defaults = [
        'userId'       => 42,
        'accessToken'  => 'apple-access-token',
        'refreshToken' => 'apple-refresh-token',
        'idToken'      => 'header.payload.sig',
        'tokenType'    => 'Bearer',
        'expiresAt'    => Carbon::now()->addHour(),
        'profile'      => new AppleUserProfile(
            sub:       '000123.abc.def',
            email:     'user@example.com',
            firstName: null,
            lastName:  null,
        ),
    ];

    $args = array_merge( $defaults, $overrides );

    return new TokenResponse(
        userId:       $args[ 'userId' ],
        accessToken:  $args[ 'accessToken' ],
        refreshToken: $args[ 'refreshToken' ],
        idToken:      $args[ 'idToken' ],
        tokenType:    $args[ 'tokenType' ],
        expiresAt:    $args[ 'expiresAt' ],
        profile:      $args[ 'profile' ],
    );
}

it( 'persists a TokenResponse as an encrypted AppleConnection row', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse() );

    expect( $connection )->toBeInstanceOf( AppleConnection::class );
    expect( $connection->exists )->toBeTrue();
    expect( $connection->user_id )->toBe( 42 );
    expect( $connection->apple_user_id )->toBe( '000123.abc.def' );
    expect( $connection->email )->toBe( 'user@example.com' );
    expect( $connection->access_token )->toBe( 'apple-access-token' );
    expect( $connection->refresh_token )->toBe( 'apple-refresh-token' );
    expect( $connection->id_token )->toBe( 'header.payload.sig' );
    expect( $connection->token_type )->toBe( 'Bearer' );
    expect( $connection->status )->toBe( AppleConnection::STATUS_CONNECTED );
    expect( $connection->isConnected() )->toBeTrue();
} );

it( 'encrypts the tokens on disk so a raw DB read never yields cleartext', function (): void {
    app( TokenManager::class )->store( tokenResponse() );

    $row = (array) DB::table( 'apple_connections' )->first();

    expect( $row[ 'access_token' ] )->not->toBe( 'apple-access-token' );
    expect( $row[ 'refresh_token' ] )->not->toBe( 'apple-refresh-token' );
    expect( $row[ 'id_token' ] )->not->toBe( 'header.payload.sig' );

    // Round-trip via Crypt to prove it is a properly encrypted payload.
    expect( Crypt::decryptString( (string) $row[ 'access_token' ] ) )->toBe( 'apple-access-token' );
    expect( Crypt::decryptString( (string) $row[ 'refresh_token' ] ) )->toBe( 'apple-refresh-token' );
} );

it( 'updates an existing connection in place rather than creating a duplicate row', function (): void {
    $manager = app( TokenManager::class );
    $manager->store( tokenResponse() );

    $manager->store( tokenResponse( [ 'accessToken' => 'second-access-token' ] ) );

    expect( AppleConnection::where( 'user_id', 42 )->count() )->toBe( 1 );
    expect( AppleConnection::where( 'user_id', 42 )->first()->access_token )
        ->toBe( 'second-access-token' );
} );

it( 'preserves an existing refresh token when a subsequent authorization omits it', function (): void {
    $manager = app( TokenManager::class );
    $manager->store( tokenResponse() );

    // Apple only issues a refresh_token on the initial authorization.
    $manager->store( tokenResponse( [ 'refreshToken' => null, 'accessToken' => 'second' ] ) );

    expect( AppleConnection::where( 'user_id', 42 )->first()->refresh_token )
        ->toBe( 'apple-refresh-token' );
} );

it( 'reconnects a previously-disconnected connection on re-authorization', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse() );
    $connection->markDisconnected( 'Refresh token revoked or expired.' );

    // User re-authorizes via the browser flow; OAuthManager::handleCallback()
    // hands us a fresh TokenResponse for the same user.
    $manager->store( tokenResponse( [ 'accessToken' => 'reauthorized' ] ) );

    $connection->refresh();
    expect( $connection->isConnected() )->toBeTrue();
    expect( $connection->status )->toBe( AppleConnection::STATUS_CONNECTED );
    expect( $connection->disconnect_reason )->toBeNull();
    expect( $connection->access_token )->toBe( 'reauthorized' );
} );

it( 'preserves a stored non-default token_type when a refresh response omits it', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'tokenType' => 'MAC',
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'refreshed',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    $manager->refresh( $connection );

    $connection->refresh();
    expect( $connection->token_type )->toBe( 'MAC' );
} );

it( 'falls back to the documented 3600s window when a refresh response omits expires_in', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'refreshed',
            'token_type'   => 'Bearer',
        ], 200 ),
    ] );

    $manager->refresh( $connection );

    $connection->refresh();
    // Must advance past the safety window so getValidAccessToken() does not
    // immediately loop back into another refresh.
    expect( $connection->isExpired() )->toBeFalse();
    expect( $connection->expires_at->isFuture() )->toBeTrue();
} );

it( 'returns the stored access token when it is still valid', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->addHour(),
    ] ) );

    Http::fake();

    expect( $manager->getValidAccessToken( $connection ) )->toBe( 'apple-access-token' );

    Http::assertNothingSent();
} );

it( 'refreshes an expired access token transparently and persists the new one', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'refreshed-access-token',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    $token = $manager->getValidAccessToken( $connection );

    expect( $token )->toBe( 'refreshed-access-token' );

    $connection->refresh();
    expect( $connection->access_token )->toBe( 'refreshed-access-token' );
    expect( $connection->isExpired() )->toBeFalse();

    Http::assertSent( function ( $request ): bool {
        return 'POST' === $request->method()
            && 'https://appleid.apple.com/auth/token' === $request->url()
            && 'refresh_token' === $request[ 'grant_type' ]
            && 'apple-refresh-token' === $request[ 'refresh_token' ]
            && 'com.example.service' === $request[ 'client_id' ]
            && 'signed-jwt-placeholder' === $request[ 'client_secret' ];
    } );
} );

it( 'preserves the stored refresh token when a refresh response omits it', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'refreshed',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    $manager->refresh( $connection );

    $connection->refresh();
    expect( $connection->refresh_token )->toBe( 'apple-refresh-token' );
    expect( $connection->access_token )->toBe( 'refreshed' );
} );

it( 'rotates the refresh token when Apple returns a new one', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token'  => 'refreshed',
            'refresh_token' => 'rotated-refresh-token',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
        ], 200 ),
    ] );

    $manager->refresh( $connection );

    $connection->refresh();
    expect( $connection->refresh_token )->toBe( 'rotated-refresh-token' );
} );

it( 'marks the connection disconnected when Apple signals invalid_grant', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'error' => 'invalid_grant',
        ], 400 ),
    ] );

    expect( fn () => $manager->getValidAccessToken( $connection ) )
        ->toThrow( TokenRefreshException::class, 'invalid_grant' );

    $connection->refresh();
    expect( $connection->isConnected() )->toBeFalse();
    expect( $connection->status )->toBe( AppleConnection::STATUS_DISCONNECTED );
    expect( $connection->disconnect_reason )->not->toBeNull();
} );

it( 'leaves the connection connected when the refresh request errors for a non-revocation reason', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'error' => 'server_error',
        ], 500 ),
    ] );

    expect( fn () => $manager->refresh( $connection ) )
        ->toThrow( TokenRefreshException::class, 'server_error' );

    $connection->refresh();
    expect( $connection->isConnected() )->toBeTrue();
    expect( $connection->access_token )->toBe( 'apple-access-token' );
} );

it( 'marks the connection disconnected when no refresh token is on file', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'refreshToken' => null,
        'expiresAt'    => Carbon::now()->subMinute(),
    ] ) );

    expect( fn () => $manager->refresh( $connection ) )
        ->toThrow( TokenRefreshException::class, 'No refresh token' );

    $connection->refresh();
    expect( $connection->isConnected() )->toBeFalse();
} );

it( 'refuses to hand out tokens for a disconnected connection', function (): void {
    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse() );
    $connection->markDisconnected( 'test' );

    $manager->getValidAccessToken( $connection );
} )->throws( TokenRefreshException::class, 'disconnected' );

it( 'refuses to refresh against a non-HTTPS token endpoint', function (): void {
    config()->set( 'apple-oauth.endpoints.token', 'http://appleid.apple.com/auth/token' );

    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    $manager->refresh( $connection );
} )->throws( TokenRefreshException::class, 'HTTPS' );

it( 'falls back to the ES256 signer for refresh when no static client_secret is configured', function (): void {
    $key = openssl_pkey_new( [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ] );
    openssl_pkey_export( $key, $pem );

    config()->set( 'apple-oauth.client_secret', '' );
    config()->set( 'apple-oauth.key_id', 'KEY1' );
    config()->set( 'apple-oauth.private_key', $pem );

    $manager    = app( TokenManager::class );
    $connection = $manager->store( tokenResponse( [
        'expiresAt' => Carbon::now()->subMinute(),
    ] ) );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'refreshed',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    $manager->refresh( $connection );

    Http::assertSent( function ( $request ): bool {
        $secret = (string) ( $request[ 'client_secret' ] ?? '' );

        // A minted JWT is three base64url segments separated by dots.
        return 3 === count( explode( '.', $secret ) )
            && str_starts_with( $secret, 'ey' );
    } );
} );

it( 'exposes the token manager via the AppleOAuth facade accessor', function (): void {
    expect( app( 'apple-oauth' )->tokens() )->toBeInstanceOf( TokenManager::class );
} );

it( 'reports isExpired() when no expires_at is stored', function (): void {
    $connection             = new AppleConnection();
    $connection->expires_at = null;

    expect( $connection->isExpired() )->toBeTrue();
} );

it( 'reports isExpired() when expires_at is within the 60-second safety window', function (): void {
    $connection             = new AppleConnection();
    $connection->expires_at = Carbon::now()->addSeconds( 30 );

    expect( $connection->isExpired() )->toBeTrue();
} );
