<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator;
use Illuminate\Support\Facades\Cache;

/**
 * Generate a fresh ES256 (prime256v1) private key and return the PEM plus the
 * matching public key so tests can round-trip a signature.
 *
 * @return array{private: string, public: string}
 */
function makeEs256KeyPair(): array
{
    $key = openssl_pkey_new( [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ] );

    if ( false === $key ) {
        throw new RuntimeException( 'Could not create test EC key.' );
    }

    openssl_pkey_export( $key, $privatePem );

    $details = openssl_pkey_get_details( $key );

    return [
        'private' => $privatePem,
        'public'  => $details[ 'key' ],
    ];
}

/**
 * Base64URL-decode a JWT segment.
 */
function b64UrlDecode( string $segment ): string
{
    $padded = str_pad( $segment, strlen( $segment ) + ( 4 - strlen( $segment ) % 4 ) % 4, '=', STR_PAD_RIGHT );

    return (string) base64_decode( strtr( $padded, '-_', '+/' ), true );
}

/**
 * Convert the raw R||S JWT signature back to DER so openssl_verify can check
 * it. Mirrors the encoder in ClientSecretGenerator::derToRawSignature().
 */
function rawSignatureToDer( string $raw ): string
{
    $half = intdiv( strlen( $raw ), 2 );
    $r    = ltrim( substr( $raw, 0, $half ), "\0" );
    $s    = ltrim( substr( $raw, $half ), "\0" );

    if ( '' === $r ) {
        $r = "\0";
    }

    if ( '' === $s ) {
        $s = "\0";
    }

    if ( 0 !== ( ord( $r[ 0 ] ) & 0x80 ) ) {
        $r = "\0" . $r;
    }

    if ( 0 !== ( ord( $s[ 0 ] ) & 0x80 ) ) {
        $s = "\0" . $s;
    }

    $encodeInteger = fn ( string $bytes ): string => "\x02" . chr( strlen( $bytes ) ) . $bytes;

    $seq = $encodeInteger( $r ) . $encodeInteger( $s );

    return "\x30" . chr( strlen( $seq ) ) . $seq;
}

beforeEach( function (): void {
    Cache::flush();

    $keys = makeEs256KeyPair();

    config()->set( 'apple-oauth.team_id', 'TEAM12345' );
    config()->set( 'apple-oauth.key_id', 'KEY67890' );
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.private_key', $keys[ 'private' ] );
    config()->set( 'apple-oauth.client_secret_ttl', 3600 );
    config()->set( 'apple-oauth.client_secret_leeway', 30 );

    $this->publicKey = $keys[ 'public' ];
} );

it( 'mints a well-formed ES256 JWT with the Apple-required claims', function (): void {
    $jwt = app( ClientSecretGenerator::class )->generate();

    $parts = explode( '.', $jwt );
    expect( $parts )->toHaveCount( 3 );

    $header = json_decode( b64UrlDecode( $parts[ 0 ] ), true );
    $claims = json_decode( b64UrlDecode( $parts[ 1 ] ), true );

    expect( $header )
        ->toHaveKey( 'alg', 'ES256' )
        ->toHaveKey( 'kid', 'KEY67890' )
        ->toHaveKey( 'typ', 'JWT' );

    expect( $claims )
        ->toHaveKey( 'iss', 'TEAM12345' )
        ->toHaveKey( 'aud', 'https://appleid.apple.com' )
        ->toHaveKey( 'sub', 'com.example.service' );

    expect( $claims[ 'exp' ] )->toBeGreaterThan( $claims[ 'iat' ] );
    expect( $claims[ 'exp' ] - $claims[ 'iat' ] )->toBe( 3600 );
} );

it( 'signs with the configured private key so the matching public key verifies it', function (): void {
    $jwt = app( ClientSecretGenerator::class )->generate();

    [ $header, $payload, $signature ] = explode( '.', $jwt );

    $rawSignature = b64UrlDecode( $signature );
    expect( strlen( $rawSignature ) )->toBe( 64 );

    $verified = openssl_verify(
        $header . '.' . $payload,
        rawSignatureToDer( $rawSignature ),
        $this->publicKey,
        OPENSSL_ALGO_SHA256,
    );

    expect( $verified )->toBe( 1 );
} );

it( 'caches the JWT so consecutive calls return the same token', function (): void {
    $generator = app( ClientSecretGenerator::class );

    $first  = $generator->generate();
    $second = $generator->generate();

    expect( $second )->toBe( $first );
} );

it( 'mints a fresh JWT after forget() invalidates the cache', function (): void {
    $generator = app( ClientSecretGenerator::class );

    $first = $generator->generate();

    // Ensure a distinct `iat` on the second mint.
    sleep( 1 );

    $generator->forget();

    $second = $generator->generate();

    expect( $second )->not->toBe( $first );
} );

it( 'accepts a private key read from a filesystem path', function (): void {
    $pem  = config( 'apple-oauth.private_key' );
    $path = tempnam( sys_get_temp_dir(), 'apple-key-' );
    file_put_contents( $path, $pem );

    config()->set( 'apple-oauth.private_key', $path );

    try {
        $jwt = app( ClientSecretGenerator::class )->generate();

        expect( $jwt )->toBeString()->not->toBeEmpty();
        expect( explode( '.', $jwt ) )->toHaveCount( 3 );
    } finally {
        @unlink( $path );
    }
} );

it( 'refuses to mint when team_id, key_id, or client_id is missing', function (): void {
    config()->set( 'apple-oauth.team_id', '' );

    app( ClientSecretGenerator::class )->generate();
} )->throws( OAuthException::class, 'team_id' );

it( 'refuses to mint when the private key is not configured', function (): void {
    config()->set( 'apple-oauth.private_key', '' );

    app( ClientSecretGenerator::class )->generate();
} )->throws( OAuthException::class, 'private_key' );

it( 'wraps an unreadable private key file path as an OAuthException', function (): void {
    config()->set( 'apple-oauth.private_key', '/path/that/does/not/exist.p8' );

    app( ClientSecretGenerator::class )->generate();
} )->throws( OAuthException::class, 'not readable' );

it( 'wraps an unparseable PEM as an OAuthException', function (): void {
    config()->set(
        'apple-oauth.private_key',
        "-----BEGIN EC PRIVATE KEY-----\nnot-a-real-key\n-----END EC PRIVATE KEY-----",
    );

    app( ClientSecretGenerator::class )->generate();
} )->throws( OAuthException::class, 'could not be parsed' );

it( 'clamps a TTL larger than Apple\'s six-month cap', function (): void {
    config()->set( 'apple-oauth.client_secret_ttl', 99_999_999 );

    $jwt    = app( ClientSecretGenerator::class )->generate();
    $claims = json_decode( b64UrlDecode( explode( '.', $jwt )[ 1 ] ), true );

    expect( $claims[ 'exp' ] - $claims[ 'iat' ] )->toBeLessThanOrEqual( 15_777_000 );
} );

it( 'rejects an RSA private key with a targeted EC-required error', function (): void {
    $rsa = openssl_pkey_new( [
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ] );
    openssl_pkey_export( $rsa, $rsaPem );

    config()->set( 'apple-oauth.private_key', $rsaPem );

    app( ClientSecretGenerator::class )->generate();
} )->throws( OAuthException::class, 'EC' );

it( 'rejects an EC key on a non-P-256 curve so an unverifiable signature is never emitted', function (): void {
    $p384 = openssl_pkey_new( [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'secp384r1',
    ] );

    if ( false === $p384 ) {
        // secp384r1 must exist on any modern OpenSSL; skip only if the platform is exotic.
        expect( true )->toBeTrue();

        return;
    }

    openssl_pkey_export( $p384, $p384Pem );
    config()->set( 'apple-oauth.private_key', $p384Pem );

    app( ClientSecretGenerator::class )->generate();
} )->throws( OAuthException::class, 'P-256' );

it( 'keys the cache by (team_id, key_id, client_id) so a credential swap mints a fresh JWT', function (): void {
    $generator = app( ClientSecretGenerator::class );

    $first = $generator->generate();

    // Swap the client_id — a distinct Services ID must produce a distinct JWT
    // (different `sub` claim), not a stale cache hit under the old key.
    config()->set( 'apple-oauth.client_id', 'com.example.other-service' );

    $second = $generator->generate();

    expect( $second )->not->toBe( $first );

    $claims = json_decode( b64UrlDecode( explode( '.', $second )[ 1 ] ), true );
    expect( $claims[ 'sub' ] )->toBe( 'com.example.other-service' );
} );

it( 'falls back to the default TTL when a nonsense value is configured', function (): void {
    config()->set( 'apple-oauth.client_secret_ttl', 0 );

    $jwt    = app( ClientSecretGenerator::class )->generate();
    $claims = json_decode( b64UrlDecode( explode( '.', $jwt )[ 1 ] ), true );

    expect( $claims[ 'exp' ] - $claims[ 'iat' ] )->toBe( 3600 );
} );
