<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Broker\BrokerClient;
use ArtisanPackUI\AppleOAuth\Broker\BrokerCredentials;
use ArtisanPackUI\AppleOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\Hooks\Facades\Filter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    $this->credentials = new BrokerCredentials( 'https://workshop.test', 'site-123', '7|plain-site-secret' );
    $this->broker      = AppleOAuth::broker( $this->credentials );
} );

afterEach( function (): void {
    Carbon::setTestNow();
} );

it( 'builds a signed authorize link that matches the broker contract', function (): void {
    Carbon::setTestNow( '2026-10-02 12:00:00' );

    $url = $this->broker->authorizationUrl( 'site-state', 'https://site.test/apple/callback', [ 'name', 'email' ] );

    expect( $url )->toStartWith( 'https://workshop.test/api/v1/oauth/apple/authorize?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['site_id'] )->toBe( 'site-123' );
    expect( $params['state'] )->toBe( 'site-state' );
    expect( $params['return_url'] )->toBe( 'https://site.test/apple/callback' );
    expect( $params['scopes'] )->toBe( 'name email' );
    expect( (int) $params['expires'] )->toBe( Carbon::now()->getTimestamp() + 300 );

    // Recompute the signature exactly as the contract describes it.
    $unsigned = $params;
    unset( $unsigned['signature'] );
    ksort( $unsigned );
    $expected = hash_hmac(
        'sha256',
        "apple\n" . http_build_query( $unsigned, '', '&', PHP_QUERY_RFC3986 ),
        hash( 'sha256', 'plain-site-secret' ),
    );

    expect( $params['signature'] )->toBe( $expected );
} );

it( 'omits scopes so the broker grants every allowed scope', function (): void {
    $url = $this->broker->authorizationUrl( 's', 'https://site.test/cb' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params )->not->toHaveKey( 'scopes' );
} );

it( 'keys signatures with the whole secret when it has no id prefix', function (): void {
    $credentials = new BrokerCredentials( 'https://workshop.test', 'site-123', 'no-pipe-secret' );

    expect( $credentials->signingKey() )->toBe( hash( 'sha256', 'no-pipe-secret' ) );
} );

it( 'exchanges the one-time code at the broker with the site secret as bearer', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/token' => Http::response( [
            'token_type'    => 'Bearer',
            'access_token'  => 'a1',
            'refresh_token' => 'r1',
            'expires_in'    => 3600,
            'scopes'        => [ 'name', 'email' ],
            'account_email' => 'user@example.com',
            'account_name'  => 'Jane Doe',
            'id_token'      => brokerIdToken(),
        ] ),
    ] );

    $tokens = $this->broker->exchangeCode( 'broker-code' );

    expect( $tokens->accessToken )->toBe( 'a1' );
    expect( $tokens->refreshToken )->toBe( 'r1' );
    expect( $tokens->scopes )->toBe( [ 'name', 'email' ] );
    expect( $tokens->profile->sub )->toBe( '000123.broker.user' );
    expect( $tokens->profile->email )->toBe( 'user@example.com' );
    expect( $tokens->profile->fullName() )->toBe( 'Jane Doe' );
    expect( $tokens->toArray()['account_name'] )->toBe( 'Jane Doe' );

    Http::assertSent( fn ( $request ): bool => 'https://workshop.test/api/v1/oauth/token' === $request->url()
        && 'Bearer 7|plain-site-secret' === $request->header( 'Authorization' )[0]
        && 'authorization_code' === $request->data()['grant_type']
        && 'broker-code' === $request->data()['code'] );
} );

it( 'accepts nullable fields in the broker response', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'token_type'    => 'Bearer',
            'access_token'  => 'a1',
            'refresh_token' => null,
            'expires_in'    => null,
            'scopes'        => [],
            'account_email' => null,
            'account_name'  => null,
            'id_token'      => brokerIdToken(),
        ] ),
    ] );

    $tokens = $this->broker->exchangeCode( 'c' );

    expect( $tokens->refreshToken )->toBeNull();
    expect( $tokens->expiresAt )->toBeNull();
    expect( $tokens->profile->email )->toBe( 'claim@privaterelay.appleid.com' );
    expect( $tokens->profile->hasName() )->toBeFalse();
} );

it( 'refuses a broker exchange without an id_token sub, since that is the stable Apple user ID', function ( mixed $idToken ): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'token_type'   => 'Bearer',
            'access_token' => 'a1',
            'scopes'       => [],
            'id_token'     => $idToken,
        ] ),
    ] );

    $this->broker->exchangeCode( 'c' );
} )->with( [
    'missing'   => [ null ],
    'malformed' => [ 'not-a-jwt' ],
    'no sub'    => [ fn (): string => brokerIdToken( [ 'sub' => '' ] ) ],
] )->throws( OAuthException::class, 'sub claim' );

it( 'surfaces broker exchange errors', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    try {
        $this->broker->exchangeCode( 'used-code' );
        $this->fail( 'Expected an OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
    }
} );

it( 'refreshes through the broker and keeps the old refresh token when none is returned', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/refresh' => Http::response( [
            'token_type'   => 'Bearer',
            'access_token' => 'fresh',
            'expires_in'   => 3600,
            'scopes'       => [ 'email' ],
        ] ),
    ] );

    $tokens = $this->broker->refresh( 'r-old' );

    expect( $tokens->accessToken )->toBe( 'fresh' );
    expect( $tokens->refreshToken )->toBe( 'r-old' );

    Http::assertSent( fn ( $request ): bool => 'r-old' === $request->data()['refresh_token']
        && 'apple' === $request->data()['provider']
        && ! array_key_exists( 'client_secret', $request->data() ) );
} );

it( 'raises a distinct exception carrying renew_url when the license has expired', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'error'     => 'license_expired',
            'renew_url' => 'https://workshop.test/renew/site-123',
        ], 402 ),
    ] );

    try {
        $this->broker->refresh( 'r1' );
        $this->fail( 'Expected a LicenseExpiredException.' );
    } catch ( LicenseExpiredException $e ) {
        expect( $e->getError() )->toBe( 'license_expired' );
        expect( $e->getRenewUrl() )->toBe( 'https://workshop.test/renew/site-123' );
    }
} );

it( 'reports broker refresh errors with their OAuth code', function ( int $status, string $error ): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => $error ], $status ) ] );

    try {
        $this->broker->refresh( 'r1' );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( LicenseExpiredException $e ) {
        $this->fail( 'Only a 402 should raise LicenseExpiredException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( $error );
    }
} )->with( [
    'revoked grant'        => [ 400, 'invalid_grant' ],
    'broker unavailable'   => [ 503, 'temporarily_unavailable' ],
    'provider unavailable' => [ 502, 'provider_unavailable' ],
] );

it( 'only trusts renew URLs on the broker host', function (): void {
    expect( $this->broker->isTrustedRenewUrl( 'https://workshop.test/renew' ) )->toBeTrue();
    expect( $this->broker->isTrustedRenewUrl( 'https://evil.test/renew' ) )->toBeFalse();
    expect( $this->broker->isTrustedRenewUrl( 'https://workshop.test.evil.test/renew' ) )->toBeFalse();
    expect( $this->broker->isTrustedRenewUrl( 'javascript:alert(1)' ) )->toBeFalse();
    expect( $this->broker->isTrustedRenewUrl( '' ) )->toBeFalse();
} );

it( 'rejects renew URLs that browsers could resolve to another host', function ( string $url ): void {
    expect( $this->broker->isTrustedRenewUrl( $url ) )->toBeFalse();
} )->with( [
    'backslash before userinfo' => 'https://evil.test\\@workshop.test/renew',
    'userinfo'                  => 'https://evil.test@workshop.test/renew',
    'user and password'         => 'https://user:pass@workshop.test/renew',
    'embedded whitespace'       => 'https://workshop.test /renew',
    'tab'                       => "https://workshop.test\t/renew",
    'newline'                   => "https://workshop.test/renew\n",
    'null byte'                 => "https://workshop.test\0/renew",
    'unparseable'               => 'https:///renew',
] );

it( 'rejects an HTTP renew URL for an HTTPS broker but allows it for a local HTTP broker', function (): void {
    expect( $this->broker->isTrustedRenewUrl( 'http://workshop.test/renew' ) )->toBeFalse();

    $local = AppleOAuth::broker( new BrokerCredentials( 'http://workshop.test', 'site-123', '7|secret' ) );

    expect( $local->isTrustedRenewUrl( 'http://workshop.test/renew' ) )->toBeTrue();
} );

it( 'treats a non-string access token as a failed exchange', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'token_type' => 'Bearer', 'access_token' => [ 'x' ], 'scopes' => [] ] ) ] );

    expect( fn () => $this->broker->exchangeCode( 'c' ) )->toThrow( OAuthException::class );
} );

it( 'resolves credentials from config and lets hosts override them via a filter', function (): void {
    config()->set( 'apple-oauth.broker.url', 'https://workshop.test/' );
    config()->set( 'apple-oauth.broker.site_id', 'from-config' );
    config()->set( 'apple-oauth.broker.site_secret', '1|secret' );

    $credentials = BrokerCredentials::fromConfig( config() );

    expect( $credentials?->url )->toBe( 'https://workshop.test' );
    expect( $credentials?->siteId )->toBe( 'from-config' );

    Filter::add( 'ap.apple-oauth.broker.credentials', fn ( array $values ): array => [ 'site_id' => 'from-cms' ] + $values );

    expect( BrokerCredentials::fromConfig( config() )?->siteId )->toBe( 'from-cms' );
} );

it( 'refuses to build a client from incomplete broker config', function (): void {
    config()->set( 'apple-oauth.broker.url', 'https://workshop.test' );

    expect( BrokerCredentials::fromConfig( config() ) )->toBeNull();
    expect( fn () => BrokerClient::fromConfig( config(), app( Illuminate\Http\Client\Factory::class ) ) )
        ->toThrow( OAuthException::class );
} );

it( 'refuses to send the site secret to a plain-HTTP broker outside local development', function ( string $url ): void {
    expect( fn () => new BrokerCredentials( $url, 'site-123', '7|secret' ) )->toThrow( OAuthException::class );
} )->with( [
    'public http host'    => 'http://workshop.example.com',
    'public http ip'      => 'http://203.0.113.10',
    'non-http scheme'     => 'ftp://workshop.test',
    'missing host'        => 'https:///api',
    'test lookalike host' => 'http://workshop.test.example.com',
] );

it( 'accepts HTTPS and local development broker URLs', function ( string $url ): void {
    expect( ( new BrokerCredentials( $url, 'site-123', '7|secret' ) )->url )->toBe( $url );
} )->with( [
    'https'           => 'https://workshop.example.com',
    'localhost'       => 'http://localhost:8000',
    'localhost alias' => 'http://workshop.localhost',
    'herd .test'      => 'http://workshop.test',
    'ipv4 loopback'   => 'http://127.0.0.1:8000',
    'ipv6 loopback'   => 'http://[::1]:8000',
] );

it( 'rejects an insecure broker URL from config', function (): void {
    config()->set( 'apple-oauth.broker.url', 'http://workshop.example.com' );
    config()->set( 'apple-oauth.broker.site_id', 'site-123' );
    config()->set( 'apple-oauth.broker.site_secret', '7|secret' );

    expect( fn () => BrokerCredentials::fromConfig( config() ) )->toThrow( OAuthException::class );
} );
