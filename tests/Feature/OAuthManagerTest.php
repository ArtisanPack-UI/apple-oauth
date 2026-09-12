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
 * Build a fake id_token JWT with the given payload, filling in Apple's
 * required claims (iss/aud/exp/nonce) from the current session unless the
 * caller overrides them.
 */
function fakeIdToken( array $claims ): string
{
    $defaults = [
        'iss'   => 'https://appleid.apple.com',
        'aud'   => 'com.example.service',
        'exp'   => time() + 3600,
        'nonce' => session( 'apple-oauth.nonce' ),
    ];

    $claims = array_merge( $defaults, $claims );

    $b64 = fn ( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );

    return $b64( '{"alg":"ES256"}' ) . '.' . $b64( (string) json_encode( $claims ) ) . '.signature';
}

/**
 * Convenience wrapper: fake a successful Apple token endpoint response with
 * an id_token whose claims are correct-by-default.
 */
function fakeTokenExchange( array $idTokenClaims = [], array $overrides = [] ): void
{
    $body = array_merge( [
        'access_token' => 'apple-access-token',
        'token_type'   => 'Bearer',
        'expires_in'   => 3600,
        'id_token'     => fakeIdToken( array_merge( [ 'sub' => 'apple-user-1' ], $idTokenClaims ) ),
    ], $overrides );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( $body, 200 ),
    ] );
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
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 7 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange(
        [ 'sub' => '000123.abc456.def789', 'email' => 'user@example.com' ],
        [ 'refresh_token' => 'apple-refresh-token' ],
    );

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

it( 'captures the first-authorization user payload for display name only', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'sub' => 'apple-user-1', 'email' => 'private@relay.appleid.com' ] );

    $userPayload = json_encode( [
        'name'  => [ 'firstName' => 'Ada', 'lastName' => 'Lovelace' ],
        'email' => 'ada@example.com',
    ] );

    $response = $manager->handleCallback( 'the-code', $state, $userPayload );

    expect( $response->profile->sub )->toBe( 'apple-user-1' );
    expect( $response->profile->firstName )->toBe( 'Ada' );
    expect( $response->profile->lastName )->toBe( 'Lovelace' );
    // Canonical email comes from the id_token (server-to-server), NOT the
    // one-shot form-post `user` payload that rides through the browser.
    expect( $response->profile->email )->toBe( 'private@relay.appleid.com' );
    expect( $response->profile->hasName() )->toBeTrue();
} );

it( 'rejects a callback whose state does not match the session', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );

    $manager->handleCallback( 'the-code', 'not-the-stored-state' );
} )->throws( OAuthException::class, 'OAuth state mismatch' );

it( 'consumes session state so replaying the same callback fails', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange();

    $manager->handleCallback( 'code', $state );

    expect( fn () => $manager->handleCallback( 'code', $state ) )
        ->toThrow( OAuthException::class );
} );

it( 'refuses a callback whose session has no associated user context', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

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

it( 'refuses to send client_secret to a non-HTTPS token endpoint', function (): void {
    config()->set( 'apple-oauth.endpoints.token', 'http://appleid.apple.com/auth/token' );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'HTTPS' );

it( 'rejects a 2xx response that omits access_token', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token'   => fakeIdToken( [ 'sub' => 'x' ] ),
        ], 200 ),
    ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'access_token' );

it( 'rejects a 2xx response that omits id_token', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'apple-access-token',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'id_token' );

it( 'rejects an id_token from an unexpected issuer', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'iss' => 'https://attacker.example.com' ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'issuer' );

it( 'rejects an id_token whose audience is not the configured client', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'aud' => 'com.someone-else.service' ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'audience' );

it( 'rejects an expired id_token', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'exp' => time() - 60 ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'expired' );

it( 'rejects an id_token whose nonce does not match the session', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'nonce' => 'not-the-session-nonce' ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'nonce' );

it( 'rejects an id_token whose sub claim is missing', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'sub' => '' ] );

    $manager->handleCallback( 'code', $state );
} )->throws( OAuthException::class, 'sub' );

it( 'accepts an id_token whose aud claim is an array containing the client', function (): void {
    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 3 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'aud' => [ 'com.example.service', 'com.other' ] ] );

    $response = $manager->handleCallback( 'code', $state );

    expect( $response->profile->sub )->toBe( 'apple-user-1' );
} );

it( 'falls back to the ES256 signer when no static client_secret is configured', function (): void {
    $key = openssl_pkey_new( [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ] );
    openssl_pkey_export( $key, $pem );

    config()->set( 'apple-oauth.client_secret', '' );
    config()->set( 'apple-oauth.key_id', 'KEY1' );
    config()->set( 'apple-oauth.private_key', $pem );

    $manager = app( OAuthManager::class );
    $manager->authorizationUrl( 1 );
    $state = session( 'apple-oauth.state' );

    fakeTokenExchange( [ 'sub' => 'apple-user-1' ] );

    $manager->handleCallback( 'code', $state );

    Http::assertSent( function ( $request ): bool {
        $secret = (string) ( $request[ 'client_secret' ] ?? '' );

        // A minted JWT is three base64url segments separated by dots.
        return 3 === count( explode( '.', $secret ) )
            && str_starts_with( $secret, 'ey' );
    } );
} );
