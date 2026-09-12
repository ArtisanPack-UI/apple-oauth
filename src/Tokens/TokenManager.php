<?php

/**
 * OAuth2 token manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Tokens;

use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator;
use ArtisanPackUI\AppleOAuth\OAuth\TokenResponse;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

/**
 * Handles persistence and refresh of Apple OAuth tokens.
 *
 * Consumers should:
 *
 *   1. Call `store()` with the {@see TokenResponse} returned by
 *      `OAuthManager::handleCallback()` to persist an encrypted connection
 *      row for the user.
 *   2. Before making an Apple API call, call `getValidAccessToken()` — the
 *      manager checks expiry and refreshes transparently via Apple's
 *      `/auth/token` endpoint with `grant_type=refresh_token`.
 *
 * On refresh failure the connection is marked disconnected when Apple
 * returns `invalid_grant` (revoked grant) or when no refresh token was ever
 * stored, and a {@see TokenRefreshException} is thrown.
 *
 * @since 1.0.0
 */
class TokenManager
{
    public function __construct(
        protected ConfigRepository $config,
        protected HttpFactory $http,
        protected ClientSecretGenerator $clientSecret,
    ) {
    }

    /**
     * Persist a {@see TokenResponse} as an encrypted {@see AppleConnection}
     * row for the response's user.
     *
     * If a connection already exists for the user it is updated in place so
     * subsequent authorizations refresh identity and tokens rather than
     * creating orphaned rows.
     *
     * Apple only releases the one-shot display name on first authorization;
     * subsequent authorizations for the same user do not re-emit it, so the
     * stored `apple_user_id`/`email` are preserved when the incoming
     * response omits them.
     *
     * @since 1.0.0
     */
    public function store( TokenResponse $response ): AppleConnection
    {
        $connection = AppleConnection::firstOrNew( [ 'user_id' => $response->userId ] );

        $connection->apple_user_id     = $response->profile->sub ?: $connection->apple_user_id;
        $connection->email             = $response->profile->email ?? $connection->email;
        $connection->access_token      = $response->accessToken;
        $connection->id_token          = $response->idToken;
        $connection->token_type        = $response->tokenType;
        $connection->expires_at        = $response->expiresAt;
        $connection->status            = AppleConnection::STATUS_CONNECTED;
        $connection->disconnect_reason = null;

        // Apple only issues a refresh_token on the initial authorization for
        // a given grant; re-authorizations without a new consent do not
        // re-emit it. Preserve whatever we already have on file rather than
        // nulling it out and silently disabling refresh for the user.
        if ( null !== $response->refreshToken && '' !== $response->refreshToken ) {
            $connection->refresh_token = $response->refreshToken;
        }

        $connection->save();

        return $connection;
    }

    /**
     * Return a valid access token, refreshing if the current one is expired.
     *
     * @since 1.0.0
     *
     * @throws TokenRefreshException When the connection cannot be refreshed.
     */
    public function getValidAccessToken( AppleConnection $connection ): string
    {
        if ( ! $connection->isConnected() ) {
            throw new TokenRefreshException( __( 'Apple connection is disconnected.' ) );
        }

        if ( ! $connection->isExpired() && ! empty( $connection->access_token ) ) {
            return (string) $connection->access_token;
        }

        return $this->refresh( $connection );
    }

    /**
     * Force a refresh regardless of expiry.
     *
     * @since 1.0.0
     *
     * @throws TokenRefreshException
     */
    public function refresh( AppleConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $clientId = (string) $this->config->get( 'apple-oauth.client_id', '' );

        if ( '' === $clientId ) {
            throw new TokenRefreshException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        $clientSecret = (string) $this->config->get( 'apple-oauth.client_secret', '' );

        if ( '' === $clientSecret ) {
            $clientSecret = $this->clientSecret->generate();
        }

        $endpoint = (string) $this->config->get(
            'apple-oauth.endpoints.token',
            'https://appleid.apple.com/auth/token',
        );

        if ( 'https' !== strtolower( (string) parse_url( $endpoint, PHP_URL_SCHEME ) ) ) {
            throw new TokenRefreshException(
                __( 'Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.' ),
            );
        }

        $response = $this->http->asForm()->post( $endpoint, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => (string) $connection->refresh_token,
            'grant_type'    => 'refresh_token',
        ] );

        if ( ! $response->successful() ) {
            $body  = $response->json();
            $error = is_array( $body ) ? ( $body[ 'error' ] ?? 'refresh_failed' ) : 'refresh_failed';

            if ( 'invalid_grant' === $error ) {
                $connection->markDisconnected( __( 'Refresh token revoked or expired.' ) );
            }

            throw new TokenRefreshException(
                __( 'Apple token refresh failed: :error', [ 'error' => (string) $error ] ),
            );
        }

        $payload = (array) $response->json();

        if ( empty( $payload[ 'access_token' ] ) ) {
            throw new TokenRefreshException( __( 'Apple token refresh response is missing access_token.' ) );
        }

        $connection->access_token = (string) $payload[ 'access_token' ];
        $connection->token_type   = (string) ( $payload[ 'token_type' ] ?? 'Bearer' );

        if ( isset( $payload[ 'expires_in' ] ) ) {
            $connection->expires_at = Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] );
        }

        // Apple typically does NOT rotate refresh tokens, but the OAuth2 spec
        // permits it. Persist a new one when returned so a rotated grant
        // does not silently disable future refreshes.
        if ( ! empty( $payload[ 'refresh_token' ] ) ) {
            $connection->refresh_token = (string) $payload[ 'refresh_token' ];
        }

        $connection->save();

        return (string) $connection->access_token;
    }
}
