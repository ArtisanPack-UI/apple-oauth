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

use ArtisanPackUI\AppleOAuth\Broker\BrokerClient;
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\AppleOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use ArtisanPackUI\AppleOAuth\OAuth\AppleClient;
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
 * stored, and a {@see TokenRefreshException} is thrown. Refreshes go to
 * Apple through the stateless {@see AppleClient}, or through the OAuth
 * broker when `apple-oauth.mode` is `broker`.
 *
 * @since 1.0.0
 */
class TokenManager
{
    public function __construct(
        protected ConfigRepository $config,
        protected HttpFactory $http,
        protected ClientSecretGenerator $clientSecret,
        protected ConfigurationRepository $credentials,
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
     *
     * @throws OAuthException When the response is not attributed to a user (a stateless response).
     */
    public function store( TokenResponse $response ): AppleConnection
    {
        if ( null === $response->userId ) {
            throw new OAuthException( __( 'Cannot store an Apple token response without a user; call withUserId() first.' ) );
        }

        $connection = AppleConnection::firstOrNew( [ 'user_id' => $response->userId ] );

        // `sub` is validated non-empty before a code-exchange TokenResponse
        // ever reaches us. `email` is nullable — Apple omits it on
        // re-authorizations after the first — so `??` preserves the
        // previously-stored address.
        $connection->apple_user_id     = $response->profile?->sub ?? $connection->apple_user_id;
        $connection->email             = $response->profile?->email ?? $connection->email;
        $connection->access_token      = $response->accessToken;
        $connection->id_token          = $response->idToken;
        $connection->token_type        = $response->tokenType;
        $connection->expires_at        = $response->expiresAt;
        $connection->status            = AppleConnection::STATUS_CONNECTED;
        $connection->disconnect_reason = null;

        if ( [] !== $response->scopes ) {
            $connection->scopes = $response->scopes;
        }

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
     * @throws LicenseExpiredException When the broker reports the site license has lapsed. The connection stays connected.
     * @throws TokenRefreshException   For any other failure. A revoked grant also marks the connection disconnected.
     */
    public function refresh( AppleConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $refreshToken = (string) $connection->refresh_token;
        $tokenType    = (string) ( $connection->token_type ?: 'Bearer' );

        try {
            $tokens = BrokerClient::isEnabled( $this->config )
                ? $this->brokerClient()->refresh( $refreshToken, $tokenType )
                : AppleClient::make( $this->credentials, $this->http, $this->clientSecret, $this->config )
                    ->refresh( $refreshToken, $tokenType );
        } catch ( TokenRefreshException $e ) {
            // Only a revoked grant disconnects. A lapsed broker license
            // (LicenseExpiredException) leaves the connection intact so
            // refreshes resume as soon as the license is renewed.
            if ( 'invalid_grant' === $e->getError() ) {
                $connection->markDisconnected( __( 'Refresh token revoked or expired.' ) );
            }

            throw $e;
        }

        $connection->access_token = $tokens->accessToken;
        $connection->token_type   = $tokens->tokenType;

        // Apple always documents an `expires_in` on a successful refresh, but
        // fall back to the documented default (3600s / one hour) rather than
        // leaving `expires_at` in the past — otherwise every subsequent call
        // to `getValidAccessToken()` would treat the token as expired and
        // hammer `/auth/token` on a loop.
        $connection->expires_at = $tokens->expiresAt ?? Carbon::now()->addSeconds( 3600 );

        // Apple typically does NOT rotate refresh tokens, but the OAuth2 spec
        // permits it. The response carries a rotated one when returned, and
        // the current one otherwise.
        if ( null !== $tokens->refreshToken ) {
            $connection->refresh_token = $tokens->refreshToken;
        }

        if ( [] !== $tokens->scopes ) {
            $connection->scopes = $tokens->scopes;
        }

        $connection->save();

        return (string) $connection->access_token;
    }

    /**
     * Broker client built from the configured broker credentials.
     *
     * @since 1.1.0
     *
     * @throws TokenRefreshException When the broker is not configured.
     */
    protected function brokerClient(): BrokerClient
    {
        try {
            return BrokerClient::fromConfig( $this->config, $this->http );
        } catch ( OAuthException $e ) {
            throw new TokenRefreshException( $e->getMessage(), 'broker_not_configured', null, $e );
        }
    }
}
