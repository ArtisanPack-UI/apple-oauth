<?php

/**
 * Apple client-secret JWT generator (ES256).
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use OpenSSLAsymmetricKey;

/**
 * Builds and caches Apple's short-lived, ES256-signed `client_secret` JWT.
 *
 * Apple requires the OAuth `client_secret` to be a JWT signed with the
 * developer's `.p8` private key (P-256 / ES256). This class mints one on
 * demand, caches it inside its own validity window (minus a leeway so a
 * cache-warmed token is never handed out on the edge of expiry), and
 * silently rotates it when the cache expires.
 *
 * @since 1.0.0
 */
class ClientSecretGenerator
{
    protected const AUDIENCE = 'https://appleid.apple.com';

    protected const CACHE_KEY_PREFIX = 'apple-oauth.client-secret.';

    protected const APPLE_MAX_TTL = 15_777_000;

    protected const DEFAULT_TTL = 3600;

    protected const DEFAULT_LEEWAY = 30;

    public function __construct(
        protected ConfigRepository $config,
        protected CacheRepository $cache,
    ) {
    }

    /**
     * Return a currently-valid client_secret JWT, minting and caching one
     * if the cache is empty or expired.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When required credentials are missing or the key cannot be loaded.
     */
    public function generate(): string
    {
        $teamId   = (string) $this->config->get( 'apple-oauth.team_id', '' );
        $keyId    = (string) $this->config->get( 'apple-oauth.key_id', '' );
        $clientId = (string) $this->config->get( 'apple-oauth.client_id', '' );

        if ( '' === $teamId || '' === $keyId || '' === $clientId ) {
            throw new OAuthException(
                __( 'Apple client-secret requires team_id, key_id, and client_id.' ),
            );
        }

        $ttl    = $this->resolveTtl();
        $leeway = $this->resolveLeeway( $ttl );

        $cacheKey = self::CACHE_KEY_PREFIX . hash( 'sha256', $teamId . '|' . $keyId . '|' . $clientId );

        $cached = $this->cache->get( $cacheKey );

        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $jwt = $this->mint( $teamId, $keyId, $clientId, $ttl );

        $this->cache->put( $cacheKey, $jwt, max( 1, $ttl - $leeway ) );

        return $jwt;
    }

    /**
     * Discard any cached client_secret so the next call mints a fresh one.
     *
     * @since 1.0.0
     */
    public function forget(): void
    {
        $teamId   = (string) $this->config->get( 'apple-oauth.team_id', '' );
        $keyId    = (string) $this->config->get( 'apple-oauth.key_id', '' );
        $clientId = (string) $this->config->get( 'apple-oauth.client_id', '' );

        $cacheKey = self::CACHE_KEY_PREFIX . hash( 'sha256', $teamId . '|' . $keyId . '|' . $clientId );

        $this->cache->forget( $cacheKey );
    }

    /**
     * Build and sign a fresh client_secret JWT.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the private key cannot be loaded or signing fails.
     */
    protected function mint( string $teamId, string $keyId, string $clientId, int $ttl ): string
    {
        $now = time();

        $header = [
            'alg' => 'ES256',
            'kid' => $keyId,
            'typ' => 'JWT',
        ];

        $claims = [
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'aud' => self::AUDIENCE,
            'sub' => $clientId,
        ];

        $signingInput = $this->base64UrlEncode( (string) json_encode( $header ) )
            . '.'
            . $this->base64UrlEncode( (string) json_encode( $claims ) );

        $signature = $this->sign( $signingInput );

        return $signingInput . '.' . $this->base64UrlEncode( $signature );
    }

    /**
     * Sign the JWT signing input with the configured EC private key.
     *
     * OpenSSL emits an ASN.1 DER-encoded ECDSA signature; JWT ES256 requires
     * the raw R||S concatenation (32 bytes each for P-256), so we translate
     * before returning.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the key cannot be loaded or signing fails.
     */
    protected function sign( string $signingInput ): string
    {
        $key = $this->loadPrivateKey();

        $derSignature = '';

        if ( ! openssl_sign( $signingInput, $derSignature, $key, OPENSSL_ALGO_SHA256 ) ) {
            throw new OAuthException( __( 'Failed to sign Apple client-secret JWT.' ) );
        }

        return $this->derToRawSignature( $derSignature );
    }

    /**
     * Load the configured `.p8` private key, from either an inline PEM string
     * or a filesystem path.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the key material is missing or invalid.
     *
     * @return OpenSSLAsymmetricKey
     */
    protected function loadPrivateKey()
    {
        $raw = (string) $this->config->get( 'apple-oauth.private_key', '' );

        if ( '' === $raw ) {
            throw new OAuthException( __( 'Apple OAuth private_key is not configured.' ) );
        }

        $pem = str_starts_with( ltrim( $raw ), '-----BEGIN' )
            ? $raw
            : $this->readKeyFile( $raw );

        $key = openssl_pkey_get_private( $pem );

        if ( false === $key ) {
            throw new OAuthException( __( 'Apple OAuth private_key could not be parsed.' ) );
        }

        $details = openssl_pkey_get_details( $key );
        $curve   = false !== $details ? ( $details[ 'ec' ][ 'curve_name' ] ?? null ) : null;

        // ES256 is defined over the P-256 curve only; OpenSSL identifies P-256 as either
        // "prime256v1" (its SEC name) or "secp256r1" (its NIST name). A P-384 key would
        // otherwise pass the type check and produce truncated (invalid) R||S in
        // derToRawSignature().
        if (
            false === $details
            || OPENSSL_KEYTYPE_EC !== ( $details[ 'type' ] ?? null )
            || ! in_array( $curve, [ 'prime256v1', 'secp256r1' ], true )
        ) {
            throw new OAuthException(
                __( 'Apple OAuth private_key must be a P-256 (prime256v1) EC key; ES256 does not accept other curves.' ),
            );
        }

        return $key;
    }

    /**
     * Read a PEM-encoded private key from disk.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the file cannot be read.
     */
    protected function readKeyFile( string $path ): string
    {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            throw new OAuthException(
                __( 'Apple OAuth private_key file is not readable: :path', [ 'path' => $path ] ),
            );
        }

        $contents = file_get_contents( $path );

        if ( false === $contents ) {
            throw new OAuthException(
                __( 'Apple OAuth private_key file could not be read: :path', [ 'path' => $path ] ),
            );
        }

        return $contents;
    }

    /**
     * Convert an ASN.1 DER-encoded ECDSA signature into the raw R||S form
     * ES256 requires (64 bytes total for the P-256 curve).
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the DER signature is malformed.
     */
    protected function derToRawSignature( string $der ): string
    {
        $offset = 0;

        if ( 0x30 !== ord( $der[ $offset++ ] ?? "\0" ) ) {
            throw new OAuthException( __( 'Malformed ECDSA signature: missing SEQUENCE tag.' ) );
        }

        $seqLength = ord( $der[ $offset++ ] ?? "\0" );

        if ( $seqLength >= 0x80 ) {
            $lengthBytes = $seqLength & 0x0F;
            $seqLength   = 0;

            for ( $i = 0; $i < $lengthBytes; $i++ ) {
                $seqLength = ( $seqLength << 8 ) | ord( $der[ $offset++ ] ?? "\0" );
            }
        }

        $r = $this->readDerInteger( $der, $offset );
        $s = $this->readDerInteger( $der, $offset );

        return $this->padComponent( $r, 32 ) . $this->padComponent( $s, 32 );
    }

    /**
     * Read one DER INTEGER off the stream at $offset (advancing $offset).
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the INTEGER tag or length is malformed.
     */
    protected function readDerInteger( string $der, int &$offset ): string
    {
        if ( 0x02 !== ord( $der[ $offset++ ] ?? "\0" ) ) {
            throw new OAuthException( __( 'Malformed ECDSA signature: missing INTEGER tag.' ) );
        }

        $length = ord( $der[ $offset++ ] ?? "\0" );

        if ( $length >= 0x80 ) {
            $lengthBytes = $length & 0x0F;
            $length      = 0;

            for ( $i = 0; $i < $lengthBytes; $i++ ) {
                $length = ( $length << 8 ) | ord( $der[ $offset++ ] ?? "\0" );
            }
        }

        $value = substr( $der, $offset, $length );
        $offset += $length;

        // DER may include a leading 0x00 to mark the value as positive; strip it.
        return ltrim( $value, "\0" );
    }

    /**
     * Left-pad a component with zero bytes to the required curve width.
     *
     * @since 1.0.0
     */
    protected function padComponent( string $value, int $width ): string
    {
        if ( strlen( $value ) > $width ) {
            $value = substr( $value, -$width );
        }

        return str_pad( $value, $width, "\0", STR_PAD_LEFT );
    }

    /**
     * Base64URL-encode a byte string per RFC 7515.
     *
     * @since 1.0.0
     */
    protected function base64UrlEncode( string $data ): string
    {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Resolve the configured JWT lifetime, clamped to Apple's documented
     * six-month maximum.
     *
     * @since 1.0.0
     */
    protected function resolveTtl(): int
    {
        $ttl = (int) $this->config->get( 'apple-oauth.client_secret_ttl', self::DEFAULT_TTL );

        if ( $ttl < 60 ) {
            $ttl = self::DEFAULT_TTL;
        }

        if ( $ttl > self::APPLE_MAX_TTL ) {
            $ttl = self::APPLE_MAX_TTL;
        }

        return $ttl;
    }

    /**
     * Resolve the cache leeway, clamped so it can never consume the full TTL.
     *
     * @since 1.0.0
     */
    protected function resolveLeeway( int $ttl ): int
    {
        $leeway = (int) $this->config->get( 'apple-oauth.client_secret_leeway', self::DEFAULT_LEEWAY );

        if ( $leeway < 0 ) {
            $leeway = self::DEFAULT_LEEWAY;
        }

        if ( $leeway >= $ttl ) {
            $leeway = (int) floor( $ttl / 2 );
        }

        return $leeway;
    }
}
