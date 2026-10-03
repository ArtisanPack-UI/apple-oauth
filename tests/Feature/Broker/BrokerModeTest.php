<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use ArtisanPackUI\AppleOAuth\OAuth\OAuthManager;
use ArtisanPackUI\AppleOAuth\Tokens\TokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    // A broker-mode site holds no Apple credentials at all.
    config()->set( 'apple-oauth.client_id', null );
    config()->set( 'apple-oauth.team_id', null );
    config()->set( 'apple-oauth.key_id', null );
    config()->set( 'apple-oauth.private_key', null );
    config()->set( 'apple-oauth.client_secret', null );
    config()->set( 'apple-oauth.redirect_uri', null );

    config()->set( 'apple-oauth.mode', 'broker' );
    config()->set( 'apple-oauth.broker.url', 'https://workshop.test' );
    config()->set( 'apple-oauth.broker.site_id', 'site-123' );
    config()->set( 'apple-oauth.broker.site_secret', '7|plain-site-secret' );
    config()->set( 'apple-oauth.broker.return_url', 'https://site.test/apple/callback' );
} );

/**
 * A broker `/token` or `/refresh` response body for Apple.
 */
function appleBrokerPayload( array $overrides = [] ): array
{
    return $overrides + [
        'token_type'    => 'Bearer',
        'access_token'  => 'broker-access',
        'refresh_token' => 'broker-refresh',
        'expires_in'    => 3600,
        'scopes'        => [ 'name', 'email' ],
        'account_email' => 'user@example.com',
        'account_name'  => 'Jane Doe',
        'id_token'      => brokerIdToken(),
    ];
}

/**
 * Store a connection for user 42 whose access token has expired.
 */
function expiredAppleConnection(): AppleConnection
{
    return AppleConnection::create( [
        'user_id'       => 42,
        'apple_user_id' => '000123.broker.user',
        'access_token'  => 'stale',
        'refresh_token' => 'broker-refresh',
        'token_type'    => 'Bearer',
        'expires_at'    => Carbon::now()->subMinute(),
        'status'        => AppleConnection::STATUS_CONNECTED,
    ] );
}

it( 'reports broker mode on the facade', function (): void {
    expect( AppleOAuth::usesBroker() )->toBeTrue();

    config()->set( 'apple-oauth.mode', 'direct' );

    expect( AppleOAuth::usesBroker() )->toBeFalse();
} );

it( 'sends the user to the broker instead of Apple, keeping only state and user in the session', function (): void {
    session()->put( 'apple-oauth.nonce', 'left-over' );

    $url = app( OAuthManager::class )->authorizationUrl( 42 );

    expect( $url )->toStartWith( 'https://workshop.test/api/v1/oauth/apple/authorize?' );

    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['site_id'] )->toBe( 'site-123' );
    expect( $params['return_url'] )->toBe( 'https://site.test/apple/callback' );
    expect( $params['scopes'] )->toBe( 'name email' );
    expect( $params['state'] )->toBe( session( 'apple-oauth.state' ) );
    expect( $params )->not->toHaveKeys( [ 'client_id', 'nonce' ] );

    expect( session( 'apple-oauth.user_id' ) )->toBe( 42 );
    expect( session()->has( 'apple-oauth.nonce' ) )->toBeFalse();
} );

it( 'refuses to build a broker link without a return URL', function (): void {
    config()->set( 'apple-oauth.broker.return_url', null );

    app( OAuthManager::class )->authorizationUrl( 42 );
} )->throws( OAuthException::class, 'return_url' );

it( 'refuses to build a broker link when the broker is not configured', function (): void {
    config()->set( 'apple-oauth.broker.site_secret', null );

    app( OAuthManager::class )->authorizationUrl( 42 );
} )->throws( OAuthException::class, 'not configured' );

it( 'exchanges the broker code and stores the connection exactly as in direct mode', function (): void {
    Http::fake( [ 'workshop.test/api/v1/oauth/token' => Http::response( appleBrokerPayload() ) ] );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 42 );

    $tokens = $manager->handleCallback( 'broker-code', (string) session( 'apple-oauth.state' ) );

    expect( $tokens->userId )->toBe( 42 );
    expect( $tokens->profile->fullName() )->toBe( 'Jane Doe' );

    $connection = AppleOAuth::tokens()->store( $tokens );

    expect( $connection->apple_user_id )->toBe( '000123.broker.user' );
    expect( $connection->email )->toBe( 'user@example.com' );
    expect( $connection->access_token )->toBe( 'broker-access' );
    expect( $connection->refresh_token )->toBe( 'broker-refresh' );
    expect( $connection->grantedScopes() )->toBe( [ 'name', 'email' ] );
    expect( $connection->isConnected() )->toBeTrue();

    expect( AppleOAuth::tokens()->getValidAccessToken( $connection ) )->toBe( 'broker-access' );

    Http::assertSent( fn ( $request ): bool => 'Bearer 7|plain-site-secret' === $request->header( 'Authorization' )[0]
        && ! array_key_exists( 'client_secret', $request->data() ) );
} );

it( 'still rejects a mismatched state in broker mode', function (): void {
    Http::fake();

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 42 );

    expect( fn () => $manager->handleCallback( 'broker-code', 'forged-state' ) )
        ->toThrow( OAuthException::class, 'state mismatch' );

    Http::assertNothingSent();
} );

it( 'refreshes through the broker without any Apple credentials', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/refresh' => Http::response( appleBrokerPayload( [
            'access_token'  => 'fresh',
            'refresh_token' => null,
        ] ) ),
    ] );

    $connection = expiredAppleConnection();

    expect( app( TokenManager::class )->getValidAccessToken( $connection ) )->toBe( 'fresh' );

    $connection->refresh();

    expect( $connection->access_token )->toBe( 'fresh' );
    expect( $connection->refresh_token )->toBe( 'broker-refresh' );
    expect( $connection->isExpired() )->toBeFalse();

    Http::assertSent( fn ( $request ): bool => 'broker-refresh' === $request->data()['refresh_token']
        && 'apple' === $request->data()['provider'] );
} );

it( 'keeps the connection connected when the license has expired', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'error'     => 'license_expired',
            'renew_url' => 'https://workshop.test/renew/site-123',
        ], 402 ),
    ] );

    $connection = expiredAppleConnection();

    try {
        app( TokenManager::class )->refresh( $connection );
        $this->fail( 'Expected a LicenseExpiredException.' );
    } catch ( LicenseExpiredException $e ) {
        expect( $e->getRenewUrl() )->toBe( 'https://workshop.test/renew/site-123' );
    }

    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'disconnects on a revoked grant reported by the broker', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    $connection = expiredAppleConnection();

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
    expect( $connection->fresh()->isConnected() )->toBeFalse();
} );

it( 'keeps the connection on a transient broker failure', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'temporarily_unavailable' ], 503 ) ] );

    $connection = expiredAppleConnection();

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'raises a refresh exception when the broker is not configured', function (): void {
    config()->set( 'apple-oauth.broker.url', null );

    try {
        app( TokenManager::class )->refresh( expiredAppleConnection() );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'broker_not_configured' );
    }
} );

it( 'only trusts renew URLs on the broker host, and only in broker mode', function (): void {
    $manager = app( OAuthManager::class );

    expect( $manager->isTrustedRenewUrl( 'https://workshop.test/renew' ) )->toBeTrue();
    expect( $manager->isTrustedRenewUrl( 'https://evil.test/renew' ) )->toBeFalse();
    expect( $manager->isTrustedRenewUrl( 'https://evil.test\\@workshop.test/renew' ) )->toBeFalse();

    config()->set( 'apple-oauth.mode', 'direct' );

    expect( $manager->isTrustedRenewUrl( 'https://workshop.test/renew' ) )->toBeFalse();
} );
