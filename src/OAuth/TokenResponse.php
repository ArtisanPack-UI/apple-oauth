<?php

/**
 * Token exchange response.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use Illuminate\Support\Carbon;

/**
 * Value object describing a successful code exchange or token refresh.
 *
 * Returned by {@see OAuthManager::handleCallback()} (with the app-side
 * `userId` filled in from the session), by the stateless
 * {@see AppleClient} (no `userId`), and by
 * {@see \ArtisanPackUI\AppleOAuth\Broker\BrokerClient}. Nothing about it is
 * persisted; pass it to `TokenManager::store()` to save it.
 * {@see self::toArray()} renders the OAuth broker's wire shape, so a broker
 * can return it as its JSON response verbatim.
 *
 * @since 1.0.0
 */
final class TokenResponse
{
    /**
     * @since 1.0.0
     * @since 1.1.0 `$userId` and `$profile` are nullable; `$expiresIn` and `$scopes` added.
     *
     * @param  int|string|null        $userId        The app-side user the authorization was initiated for; null for stateless responses.
     * @param  string                 $accessToken   Short-lived access token.
     * @param  string|null            $refreshToken  Long-lived refresh token; the previous one when the provider did not return a new one.
     * @param  string|null            $idToken       Raw id_token JWT (signature unverified).
     * @param  string                 $tokenType     Token type; Apple always returns `Bearer`.
     * @param  Carbon|null            $expiresAt     When the access token expires.
     * @param  AppleUserProfile|null  $profile       Identity from the id_token (+ first-auth `user` payload). Always set on a code exchange; null on a refresh without an id_token.
     * @param  int|null               $expiresIn     Access-token lifetime in seconds, when reported.
     * @param  list<string>           $scopes        Granted scopes; empty when not reported (Apple's token endpoint never reports them).
     */
    public function __construct(
        public readonly int|string|null $userId,
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $idToken,
        public readonly string $tokenType,
        public readonly ?Carbon $expiresAt,
        public readonly ?AppleUserProfile $profile,
        public readonly ?int $expiresIn = null,
        public readonly array $scopes = [],
    ) {
    }

    /**
     * Build from an Apple `/auth/token` payload.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>   $payload               Decoded JSON from Apple's token endpoint. Must contain `access_token`.
     * @param  AppleUserProfile|null  $profile               Identity resolved from the payload's id_token.
     * @param  string|null            $fallbackRefreshToken  Refresh token to keep when Apple does not return one.
     * @param  string                 $fallbackTokenType     Token type to keep when Apple does not report one.
     */
    public static function fromApple(
        array $payload,
        ?AppleUserProfile $profile,
        ?string $fallbackRefreshToken = null,
        string $fallbackTokenType = 'Bearer',
    ): self {
        $scopes = isset( $payload['scope'] ) ? explode( ' ', (string) $payload['scope'] ) : [];

        return self::build( $payload, $scopes, $profile, $fallbackRefreshToken, $fallbackTokenType );
    }

    /**
     * Build from an OAuth broker `/token` or `/refresh` payload.
     *
     * The profile's `sub` comes from the id_token; the broker's
     * `account_email` and `account_name` take precedence over its claims.
     * The profile is null when the broker sent no usable id_token.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $payload               Decoded JSON from the broker. Must contain `access_token`.
     * @param  string|null           $fallbackRefreshToken  Refresh token to keep when the broker does not return one.
     * @param  string                $fallbackTokenType     Token type to keep when the broker does not report one.
     */
    public static function fromBroker(
        array $payload,
        ?string $fallbackRefreshToken = null,
        string $fallbackTokenType = 'Bearer',
    ): self {
        $scopes  = is_array( $payload['scopes'] ?? null ) ? $payload['scopes'] : [];
        $idToken = self::stringOrNull( $payload['id_token'] ?? null );
        $claims  = [];

        if ( null !== $idToken ) {
            try {
                $claims = AppleClient::decodeIdToken( $idToken );
            } catch ( OAuthException ) {
                $claims = [];
            }
        }

        $sub     = self::stringOrNull( $claims['sub'] ?? null );
        $profile = null === $sub ? null : new AppleUserProfile(
            sub: $sub,
            email: self::stringOrNull( $payload['account_email'] ?? null ) ?? self::stringOrNull( $claims['email'] ?? null ),
            displayName: self::stringOrNull( $payload['account_name'] ?? null ),
        );

        return self::build( $payload, $scopes, $profile, $fallbackRefreshToken, $fallbackTokenType );
    }

    /**
     * Copy of this response attributed to an app-side user.
     *
     * @since 1.1.0
     */
    public function withUserId( int|string $userId ): self
    {
        return new self(
            userId: $userId,
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            idToken: $this->idToken,
            tokenType: $this->tokenType,
            expiresAt: $this->expiresAt,
            profile: $this->profile,
            expiresIn: $this->expiresIn,
            scopes: $this->scopes,
        );
    }

    /**
     * Render in the OAuth broker's site-facing response shape.
     *
     * @since 1.1.0
     *
     * @return array{token_type: string, access_token: string, refresh_token: ?string, expires_in: ?int, scopes: list<string>, account_email: ?string, account_name: ?string, id_token: ?string}
     */
    public function toArray(): array
    {
        return [
            'token_type'    => $this->tokenType,
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_in'    => $this->expiresIn,
            'scopes'        => $this->scopes,
            'account_email' => $this->profile?->email,
            'account_name'  => $this->profile?->fullName(),
            'id_token'      => $this->idToken,
        ];
    }

    /**
     * Shared builder for both payload shapes.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>     $scopes
     */
    private static function build(
        array $payload,
        array $scopes,
        ?AppleUserProfile $profile,
        ?string $fallbackRefreshToken,
        string $fallbackTokenType,
    ): self {
        $expiresIn = isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : null;

        $scopes = array_map( 'trim', array_map( 'strval', $scopes ) );
        $scopes = array_values( array_filter( $scopes, static fn ( string $scope ): bool => '' !== $scope ) );

        return new self(
            userId: null,
            accessToken: (string) $payload['access_token'],
            refreshToken: self::stringOrNull( $payload['refresh_token'] ?? null ) ?? $fallbackRefreshToken,
            idToken: self::stringOrNull( $payload['id_token'] ?? null ),
            tokenType: self::stringOrNull( $payload['token_type'] ?? null ) ?? $fallbackTokenType,
            expiresAt: null === $expiresIn ? null : Carbon::now()->addSeconds( $expiresIn ),
            profile: $profile,
            expiresIn: $expiresIn,
            scopes: $scopes,
        );
    }

    /**
     * Normalize a scalar payload value to a non-empty string or null.
     *
     * @since 1.1.0
     */
    private static function stringOrNull( mixed $value ): ?string
    {
        if ( ! is_scalar( $value ) || '' === (string) $value ) {
            return null;
        }

        return (string) $value;
    }
}
