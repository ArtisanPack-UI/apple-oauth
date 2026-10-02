<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use ArtisanPackUI\AppleOAuth\OAuth\AppleClient;
use ArtisanPackUI\AppleOAuth\OAuth\AppleCredentials;
use ArtisanPackUI\AppleOAuth\OAuth\AppleUserProfile;
use ArtisanPackUI\AppleOAuth\OAuth\TokenResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

/**
 * Build an unsigned id_token JWT for the relay tests, defaulting the claims
 * Apple always sends to values that pass validation for `com.relay.app`.
 */
function relayIdToken( array $claims = [] ): string
{
    $claims = array_merge( [
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.relay.app',
        'exp' => time() + 3600,
        'sub' => 'relay-user-1',
    ], $claims );

    $b64 = fn ( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );

    return $b64( '{"alg":"ES256"}' ) . '.' . $b64( (string) json_encode( $claims ) ) . '.signature';
}

beforeEach( function (): void {
    Cache::flush();

    // The globally configured app must never leak into a relay call.
    config()->set( 'apple-oauth.client_id', 'com.configured.app' );
    config()->set( 'apple-oauth.redirect_uri', 'https://configured.test/callback' );
    config()->set( 'apple-oauth.client_secret', 'configured-secret' );

    $key = openssl_pkey_new( [ 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ] );
    openssl_pkey_export( $key, $pem );

    $this->credentials = new AppleCredentials(
        clientId: 'com.relay.app',
        teamId: 'RELAYTEAM',
        keyId: 'RELAYKEY',
        privateKey: $pem,
        redirectUri: 'https://broker.test/apple/callback',
    );

    $this->client = AppleOAuth::client( $this->credentials );
} );

it( 'builds a consent URL from runtime credentials and caller state without touching the session', function (): void {
    $url = $this->client->authorizationUrl( 'caller-state', [ 'name', 'email' ], 'caller-nonce', [ 'locale' => 'en_US' ] );

    expect( $url )->toStartWith( 'https://appleid.apple.com/auth/authorize?' );

    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query )
        ->toHaveKey( 'client_id', 'com.relay.app' )
        ->toHaveKey( 'redirect_uri', 'https://broker.test/apple/callback' )
        ->toHaveKey( 'response_type', 'code' )
        ->toHaveKey( 'response_mode', 'form_post' )
        ->toHaveKey( 'scope', 'name email' )
        ->toHaveKey( 'state', 'caller-state' )
        ->toHaveKey( 'nonce', 'caller-nonce' )
        ->toHaveKey( 'locale', 'en_US' );

    expect( session()->all() )->not->toHaveKeys( [ 'apple-oauth.state', 'apple-oauth.nonce', 'apple-oauth.user_id' ] );
} );

it( 'omits the nonce when the caller sends none', function (): void {
    $url = $this->client->authorizationUrl( 's', [ 'email' ] );

    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query )->not->toHaveKey( 'nonce' );
} );

it( 'refuses to build a consent URL without a redirect URI', function (): void {
    AppleOAuth::client( new AppleCredentials( 'com.relay.app' ) )->authorizationUrl( 's', [ 'email' ] );
} )->throws( OAuthException::class, 'not configured' );

it( 'falls back to the configured driver when no credentials are passed', function (): void {
    expect( AppleOAuth::client()->credentials()->clientId )->toBe( 'com.configured.app' );
    expect( AppleOAuth::client()->clientSecret() )->toBe( 'configured-secret' );
} );

it( 'exchanges a code with a minted secret and parses the first-authorization user payload, persisting nothing', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token'  => 'relay-access',
            'refresh_token' => 'relay-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'id_token'      => relayIdToken( [ 'nonce' => 'n-1', 'email' => 'relay@privaterelay.appleid.com' ] ),
        ] ),
    ] );

    $tokens = $this->client->exchangeCode(
        'the-code',
        'n-1',
        (string) json_encode( [ 'name' => [ 'firstName' => 'Ada', 'lastName' => 'Lovelace' ] ] ),
    );

    expect( $tokens->userId )->toBeNull();
    expect( $tokens->accessToken )->toBe( 'relay-access' );
    expect( $tokens->refreshToken )->toBe( 'relay-refresh' );
    expect( $tokens->expiresIn )->toBe( 3600 );
    expect( $tokens->expiresAt )->not->toBeNull();
    expect( $tokens->profile->sub )->toBe( 'relay-user-1' );
    expect( $tokens->profile->email )->toBe( 'relay@privaterelay.appleid.com' );
    expect( $tokens->profile->fullName() )->toBe( 'Ada Lovelace' );

    expect( AppleConnection::count() )->toBe( 0 );
    expect( session()->all() )->not->toHaveKey( 'apple-oauth.state' );

    Http::assertSent( function ( $request ): bool {
        $secret = (string) $request['client_secret'];

        return 'com.relay.app' === $request['client_id']
            && 'https://broker.test/apple/callback' === $request['redirect_uri']
            && 'the-code' === $request['code']
            && 3 === count( explode( '.', $secret ) )
            && 'configured-secret' !== $secret;
    } );
} );

it( 'skips the nonce check when the caller expects none', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'a',
            'id_token'     => relayIdToken(),
        ] ),
    ] );

    expect( $this->client->exchangeCode( 'code' )->profile->sub )->toBe( 'relay-user-1' );
} );

it( 'rejects an id_token whose nonce does not match the caller nonce', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'a',
            'id_token'     => relayIdToken( [ 'nonce' => 'other' ] ),
        ] ),
    ] );

    $this->client->exchangeCode( 'code', 'expected' );
} )->throws( OAuthException::class, 'nonce mismatch' );

it( 'validates the id_token audience against the runtime Services ID', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'a',
            'id_token'     => relayIdToken( [ 'aud' => 'com.configured.app' ] ),
        ] ),
    ] );

    $this->client->exchangeCode( 'code' );
} )->throws( OAuthException::class, 'audience mismatch' );

it( 'exposes the Apple error code on a failed exchange', function (): void {
    Http::fake( [ 'https://appleid.apple.com/auth/token' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    try {
        $this->client->exchangeCode( 'used' );
        $this->fail( 'Expected an OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
    }
} );

it( 'refreshes from a raw refresh-token string and hands the same refresh token back', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => 'fresh',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'id_token'     => relayIdToken( [ 'email' => 'relay@example.com' ] ),
        ] ),
    ] );

    $tokens = $this->client->refresh( 'raw-refresh' );

    expect( $tokens->accessToken )->toBe( 'fresh' );
    expect( $tokens->refreshToken )->toBe( 'raw-refresh' );
    expect( $tokens->profile->sub )->toBe( 'relay-user-1' );
    expect( $tokens->profile->email )->toBe( 'relay@example.com' );
    expect( AppleConnection::count() )->toBe( 0 );

    Http::assertSent( fn ( $request ): bool => 'refresh_token' === $request['grant_type']
        && 'raw-refresh' === $request['refresh_token']
        && 'com.relay.app' === $request['client_id'] );
} );

it( 'returns a profile-less refresh response when Apple sends no id_token', function (): void {
    Http::fake( [ 'https://appleid.apple.com/auth/token' => Http::response( [ 'access_token' => 'fresh' ] ) ] );

    $tokens = $this->client->refresh( 'raw-refresh', 'MAC' );

    expect( $tokens->profile )->toBeNull();
    expect( $tokens->tokenType )->toBe( 'MAC' );
    expect( $tokens->expiresAt )->toBeNull();
} );

it( 'reports invalid_grant on a revoked refresh token', function (): void {
    Http::fake( [ 'https://appleid.apple.com/auth/token' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    try {
        $this->client->refresh( 'revoked' );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
    }
} );

it( 'prefers a static client secret on runtime credentials over the signer', function (): void {
    $client = AppleOAuth::client( new AppleCredentials( 'com.relay.app', clientSecret: 'pre-minted' ) );

    expect( $client->clientSecret() )->toBe( 'pre-minted' );
} );

it( 'verifies the caller state', function (): void {
    AppleClient::verifyState( 'expected', 'expected' );

    expect( fn () => AppleClient::verifyState( 'expected', 'forged' ) )->toThrow( OAuthException::class, 'state mismatch' );
    expect( fn () => AppleClient::verifyState( null, 'anything' ) )->toThrow( OAuthException::class, 'state mismatch' );
} );

it( 'renders the broker wire shape and attributes stateless responses to a user', function (): void {
    $tokens = new TokenResponse(
        userId: null,
        accessToken: 'a',
        refreshToken: 'r',
        idToken: 'i.d.t',
        tokenType: 'Bearer',
        expiresAt: null,
        profile: new AppleUserProfile( 'sub-1', 'user@example.com', 'Ada', 'Lovelace' ),
        expiresIn: 3600,
        scopes: [ 'name', 'email' ],
    );

    expect( $tokens->toArray() )->toBe( [
        'token_type'    => 'Bearer',
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'expires_in'    => 3600,
        'scopes'        => [ 'name', 'email' ],
        'account_email' => 'user@example.com',
        'account_name'  => 'Ada Lovelace',
        'id_token'      => 'i.d.t',
    ] );

    $attributed = $tokens->withUserId( 9 );

    expect( $attributed->userId )->toBe( 9 );
    expect( $attributed->toArray() )->toBe( $tokens->toArray() );
} );

it( 'refuses to store a stateless response that has no user', function (): void {
    $tokens = new TokenResponse( null, 'a', 'r', null, 'Bearer', null, new AppleUserProfile( 'sub-1' ) );

    AppleOAuth::tokens()->store( $tokens );
} )->throws( OAuthException::class, 'without a user' );

it( 'treats a non-string access token as a failed exchange or refresh', function (): void {
    Http::fake( [
        'https://appleid.apple.com/auth/token' => Http::response( [
            'access_token' => [ 'not', 'a', 'string' ],
            'id_token'     => relayIdToken(),
        ] ),
    ] );

    expect( fn () => $this->client->exchangeCode( 'code' ) )->toThrow( OAuthException::class, 'missing access_token' );
    expect( fn () => $this->client->refresh( 'r' ) )->toThrow( TokenRefreshException::class, 'missing access_token' );
} );
