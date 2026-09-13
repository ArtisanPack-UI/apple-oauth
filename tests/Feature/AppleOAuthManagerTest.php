<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\AppleOAuth;
use ArtisanPackUI\AppleOAuth\AppleOAuthManager;
use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use ArtisanPackUI\AppleOAuth\Tokens\OAuthTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );
    config()->set( 'apple-oauth.client_secret', 'signed-jwt-placeholder' );
} );

function seedConnection( array $overrides = [] ): AppleConnection
{
    return AppleConnection::create( array_merge( [
        'user_id'       => 42,
        'apple_user_id' => '000123.abc.def',
        'email'         => 'user@example.com',
        'access_token'  => 'stored-access-token',
        'refresh_token' => 'stored-refresh-token',
        'id_token'      => 'header.payload.sig',
        'token_type'    => 'Bearer',
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => AppleConnection::STATUS_CONNECTED,
    ], $overrides ) );
}

it( 'binds the default OAuthTokenProvider as the TokenProvider contract', function (): void {
    expect( app( TokenProvider::class ) )
        ->toBeInstanceOf( OAuthTokenProvider::class );
} );

it( 'binds AppleOAuthManager in the container', function (): void {
    expect( app( AppleOAuthManager::class ) )
        ->toBeInstanceOf( AppleOAuthManager::class );
} );

it( 'exposes the manager via the AppleOAuth facade accessor', function (): void {
    expect( app( AppleOAuth::class )->manager() )
        ->toBeInstanceOf( AppleOAuthManager::class );
} );

it( 'exposes the token provider via manager()->tokens()', function (): void {
    /** @var AppleOAuthManager $manager */
    $manager = app( AppleOAuthManager::class );

    expect( $manager->tokens() )->toBeInstanceOf( TokenProvider::class );
} );

it( 'OAuthTokenProvider delegates to TokenManager::getValidAccessToken', function (): void {
    $connection = seedConnection();

    /** @var OAuthTokenProvider $provider */
    $provider = app( TokenProvider::class );

    expect( $provider->accessTokenFor( $connection ) )->toBe( 'stored-access-token' );
} );

it( 'OAuthTokenProvider surfaces refresh failures as TokenRefreshException', function (): void {
    $connection = seedConnection( [
        'status'            => AppleConnection::STATUS_DISCONNECTED,
        'disconnect_reason' => 'Revoked.',
    ] );

    /** @var OAuthTokenProvider $provider */
    $provider = app( TokenProvider::class );

    expect( fn () => $provider->accessTokenFor( $connection ) )
        ->toThrow( TokenRefreshException::class );
} );

it( 'request() builds a PendingRequest carrying the current bearer token', function (): void {
    Http::fake( [
        'https://api.example.test/*' => Http::response( [ 'ok' => true ], 200 ),
    ] );

    $connection = seedConnection();

    /** @var AppleOAuthManager $manager */
    $manager = app( AppleOAuthManager::class );

    $response = $manager->request( $connection )->get( 'https://api.example.test/ping' );

    expect( $response->successful() )->toBeTrue();

    Http::assertSent( function ( $request ): bool {
        return 'Bearer stored-access-token' === $request->header( 'Authorization' )[ 0 ];
    } );
} );

it( 'request() refreshes an expired token before returning the request', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'refreshed-access-token',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ], 200 ),
        'https://api.example.test/*' => Http::response( [ 'ok' => true ], 200 ),
    ] );

    $connection = seedConnection( [
        'access_token' => 'expired-access-token',
        'expires_at'   => Carbon::now()->subMinute(),
    ] );

    /** @var AppleOAuthManager $manager */
    $manager = app( AppleOAuthManager::class );

    $manager->request( $connection )->get( 'https://api.example.test/ping' );

    Http::assertSent( function ( $request ): bool {
        return str_contains( (string) $request->url(), 'api.example.test' )
            && 'Bearer refreshed-access-token' === $request->header( 'Authorization' )[ 0 ];
    } );
} );

it( 'request() propagates TokenRefreshException instead of the HTTP call swallowing it', function (): void {
    Http::fake();

    $connection = seedConnection( [
        'status'            => AppleConnection::STATUS_DISCONNECTED,
        'disconnect_reason' => 'Revoked.',
    ] );

    /** @var AppleOAuthManager $manager */
    $manager = app( AppleOAuthManager::class );

    expect( fn () => $manager->request( $connection ) )
        ->toThrow( TokenRefreshException::class );

    Http::assertNothingSent();
} );

it( 'lets consumers swap the TokenProvider binding with a fake', function (): void {
    Http::fake( [
        'https://api.example.test/*' => Http::response( [ 'ok' => true ], 200 ),
    ] );

    app()->instance( TokenProvider::class, new class implements TokenProvider {
        public function accessTokenFor( AppleConnection $connection ): string
        {
            return 'fake-token-' . $connection->getKey();
        }
    } );

    // Rebuild the manager so it picks up the swapped provider. Real
    // consumers would resolve the manager after their fake is bound.
    app()->forgetInstance( AppleOAuthManager::class );

    $connection = seedConnection();

    app( AppleOAuthManager::class )->request( $connection )
        ->get( 'https://api.example.test/ping' );

    Http::assertSent( function ( $request ) use ( $connection ): bool {
        return 'Bearer fake-token-' . $connection->getKey() === $request->header( 'Authorization' )[ 0 ];
    } );
} );
