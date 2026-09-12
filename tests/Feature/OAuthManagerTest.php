<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\OAuth\OAuthManager;
use ArtisanPackUI\AppleOAuth\OAuth\TokenResponse;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.team_id', 'TEAM1234' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/oauth/apple/callback' );
    config()->set( 'apple-oauth.client_secret', 'signed-jwt-placeholder' );
    config()->set( 'apple-oauth.scopes', [ 'name', 'email' ] );
} );

/**
 * Build a fake id_token JWT with the given payload.
 */
function fakeIdToken( array $claims ): string
{
    $b64 = fn ( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );

    return $b64( '{"alg":"ES256"}' ) . '.' . $b64( (string) json_encode( $claims ) ) . '.signature';
}

it( 'builds an authorization URL with state, form_post response mode, and the requested scopes', function (): void {
    $manager = app( OAuthManager::class );

    $url = $manager->authorizationUrl( 42 );

    expect( $url )->toStartWith( 'https://appleid.apple.com/auth/authorize?' );

    $query = [];
    parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query )
        ->toHaveKey( 'client_id', 'com.example.service' )
        ->toHaveKey( 'redirect_uri', 'https://example.test/oauth/apple/callback' )
        ->toHaveKey( 'response_type', 'code' )
        ->toHaveKey( 'response_mode', 'form_post' )
        ->toHaveKey( 'scope', 'name email' );

    expect( $query[ 'state' ] )->toBeString()->not->toBeEmpty();
    expect( $query[ 'nonce' ] )->toBeString()->not->toBeEmpty();

    expect( session( 'apple-oauth.state' ) )->toBe( $query[ 'state' ] );
    expect( session( 'apple-oauth.nonce' ) )->toBe( $query[ 'nonce' ] );
    expect( session( 'apple-oauth.user_id' ) )->toBe( 42 );
} );

it( 'lets callers override the scopes requested from Apple', function (): void {
    $manager = app( OAuthManager::class );

    $url = $manager->authorizationUrl( 1, [ 'email' ] );

    $query = [];
    parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query[ 'scope' ] )->toBe( 'email' );
} );

it( 'refuses to build an authorization URL when credentials are missing', function (): void {
    config()->set( 'apple-oauth.client_id', '' );

    app( OAuthManager::class )->authorizationUrl( 1 );
} )->throws( OAuthException::class );

it( 'exchanges the code for tokens and returns a TokenResponse with the id_token identity', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token'  => 'apple-access-token',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'refresh_token' => 'apple-refresh-token',
            'id_token'      => fakeIdToken( [
                'sub'   => '000123.abc456.def789',
                'email' => 'user@example.com',
            ] ),
        ], 200 ),
    ] );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 7 );
    $state = session( 'apple-oauth.state' );

    $response = $manager->handleCallback( 'the-code', $state );

    expect( $response )->toBeInstanceOf( TokenResponse::class );
    expect( $response->userId )->toBe( 7 );
    expect( $response->accessToken )->toBe( 'apple-access-token' );
    expect( $response->refreshToken )->toBe( 'apple-refresh-token' );
    expect( $response->tokenType )->toBe( 'Bearer' );
    expect( $response->expiresAt )->not->toBeNull();
    expect( $response->profile->sub )->toBe( '000123.abc456.def789' );
    expect( $response->profile->email )->toBe( 'user@example.com' );
    expect( $response->profile->hasName() )->toBeFalse();

    Http::assertSent( function ( $request ): bool {
        return 'POST' === $request->method()
            && 'https://appleid.apple.com/auth/token' === $request->url()
            && 'authorization_code' === $request[ 'grant_type' ]
            && 'the-code' === $request[ 'code' ]
            && 'com.example.service' === $request[ 'client_id' ]
            && 'signed-jwt-placeholder' === $request[ 'client_secret' ]
            && 'https://example.test/oauth/apple/callback' === $request[ 'redirect_uri' ];
    } );
} );

it( 'captures the first-authorization user payload with the release name and email', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'apple-access-token',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'id_token'     => fakeIdToken( [ 'sub' => 'apple-user-1', 'email' => 'private@relay.appleid.com' ] ),
        ], 200 ),
    ] );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    $userPayload = json_encode( [
        'name'  => [ 'firstName' => 'Ada', 'lastName' => 'Lovelace' ],
        'email' => 'ada@example.com',
    ] );

    $response = $manager->handleCallback( 'the-code', $state, $userPayload );

    expect( $response->profile->sub )->toBe( 'apple-user-1' );
    expect( $response->profile->firstName )->toBe( 'Ada' );
    expect( $response->profile->lastName )->toBe( 'Lovelace' );
    expect( $response->profile->email )->toBe( 'ada@example.com' );
    expect( $response->profile->hasName() )->toBeTrue();
} );

it( 'rejects a callback whose state does not match the session', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );

    $manager->handleCallback( 'the-code', 'not-the-stored-state' );
} )->throws( OAuthException::class, 'OAuth state mismatch' );

it( 'consumes session state so replaying the same callback fails', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'a',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'id_token'     => fakeIdToken( [ 'sub' => 'x' ] ),
        ], 200 ),
    ] );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    $manager->handleCallback( 'code', $state );

    expect( fn () => $manager->handleCallback( 'code', $state ) )
        ->toThrow( OAuthException::class );
} );

it( 'refuses a callback whose session has no associated user context', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    // Simulate the session losing the user context (e.g. session regeneration
    // between the redirect and the callback).
    session()->forget( 'apple-oauth.user_id' );

    $manager->handleCallback( 'the-code', $state );
} )->throws( OAuthException::class, 'user context' );

it( 'wraps Apple token-endpoint failures in an OAuthException', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'error' => 'invalid_grant',
        ], 400 ),
    ] );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    $manager->handleCallback( 'bad-code', $state );
} )->throws( OAuthException::class, 'invalid_grant' );
