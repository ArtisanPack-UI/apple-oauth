<?php

/**
 * Apple OAuth2 authorization-code flow manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Drives Sign in with Apple's OAuth2 authorization-code flow.
 *
 * `authorizationUrl()` builds the URL to redirect the user to, stashing the
 * anti-forgery `state` and nonce in the session. `handleCallback()` verifies
 * the returned `state`, redeems the authorization `code` for tokens, and
 * captures the user's display name and email from the one-shot `user`
 * payload Apple releases on first authorization.
 *
 * @since 1.0.0
 */
class OAuthManager
{
    protected const SESSION_STATE   = 'apple-oauth.state';

    protected const SESSION_NONCE   = 'apple-oauth.nonce';

    protected const SESSION_USER_ID = 'apple-oauth.user_id';

    public function __construct(
        protected ConfigRepository $config,
        protected Session $session,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Build the Apple authorization URL for a given user.
     *
     * Apple requires `response_mode=form_post` whenever `name` or `email`
     * scopes are requested — Apple only posts the `user` payload back once
     * (on the first authorization) and refuses to release it over the
     * fragment or query response modes.
     *
     * @since 1.0.0
     *
     * @param  int|string                $userId    The user we are connecting an Apple ID to.
     * @param  array<int, string>|null   $override  Explicit scopes; defaults to config.
     *
     * @throws OAuthException When required credentials are missing.
     */
    public function authorizationUrl( int|string $userId, ?array $override = null ): string
    {
        $clientId    = (string) $this->config->get( 'apple-oauth.client_id', '' );
        $redirectUri = (string) $this->config->get( 'apple-oauth.redirect_uri', '' );

        if ( '' === $clientId || '' === $redirectUri ) {
            throw new OAuthException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        $scopes = $override ?? (array) $this->config->get( 'apple-oauth.scopes', [ 'name', 'email' ] );

        $state = Str::random( 40 );
        $nonce = Str::random( 40 );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->put( self::SESSION_NONCE, $nonce );
        $this->session->put( self::SESSION_USER_ID, $userId );

        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope'         => implode( ' ', $scopes ),
            'state'         => $state,
            'nonce'         => $nonce,
        ];

        $endpoint = (string) $this->config->get(
            'apple-oauth.endpoints.authorize',
            'https://appleid.apple.com/auth/authorize',
        );

        return $endpoint . '?' . http_build_query( $params );
    }

    /**
     * Handle the callback Apple form-posts to the redirect URI.
     *
     * Verifies the returned `state` against the session, exchanges the
     * authorization `code` for tokens, and merges any first-authorization
     * `user` payload (name/email) into the returned profile. The `user`
     * argument, when present, is the raw JSON string Apple posts back —
     * it is provided exactly once and must be captured immediately.
     *
     * @since 1.0.0
     *
     * @param  string       $code           Authorization code from Apple.
     * @param  string       $returnedState  The state Apple echoed back.
     * @param  string|null  $userPayload    Raw JSON `user` field from the first-authorization POST body.
     *
     * @throws OAuthException On state mismatch, missing session context, or token-exchange failure.
     */
    public function handleCallback( string $code, string $returnedState, ?string $userPayload = null ): TokenResponse
    {
        $storedState = $this->session->pull( self::SESSION_STATE );
        $this->session->pull( self::SESSION_NONCE );
        $userId      = $this->session->pull( self::SESSION_USER_ID );

        if ( empty( $storedState ) || ! hash_equals( (string) $storedState, $returnedState ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }

        if ( null === $userId ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $clientId     = (string) $this->config->get( 'apple-oauth.client_id', '' );
        $redirectUri  = (string) $this->config->get( 'apple-oauth.redirect_uri', '' );
        $clientSecret = (string) $this->config->get( 'apple-oauth.client_secret', '' );

        if ( '' === $clientId || '' === $redirectUri || '' === $clientSecret ) {
            throw new OAuthException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        $endpoint = (string) $this->config->get(
            'apple-oauth.endpoints.token',
            'https://appleid.apple.com/auth/token',
        );

        $response = $this->http->asForm()->post( $endpoint, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ] );

        if ( ! $response->successful() ) {
            $body  = $response->json();
            $error = is_array( $body ) ? ( $body[ 'error' ] ?? 'exchange_failed' ) : 'exchange_failed';

            throw new OAuthException(
                __( 'Apple code exchange failed: :error', [ 'error' => (string) $error ] ),
            );
        }

        $payload = (array) $response->json();

        $expiresAt = isset( $payload[ 'expires_in' ] )
            ? Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] )
            : null;

        $profile = $this->buildProfile( $payload[ 'id_token' ] ?? null, $userPayload );

        return new TokenResponse(
            userId:       $userId,
            accessToken:  (string) ( $payload[ 'access_token' ] ?? '' ),
            refreshToken: isset( $payload[ 'refresh_token' ] ) ? (string) $payload[ 'refresh_token' ] : null,
            idToken:      isset( $payload[ 'id_token' ] )      ? (string) $payload[ 'id_token' ]      : null,
            tokenType:    (string) ( $payload[ 'token_type' ] ?? 'Bearer' ),
            expiresAt:    $expiresAt,
            profile:      $profile,
        );
    }

    /**
     * Merge the id_token's `sub`/`email` claims with the one-shot `user`
     * payload Apple only releases on the first authorization.
     *
     * The id_token JWT is trusted for identity because it comes back from
     * the TLS-terminated exchange with Apple; signature verification is
     * not required for identity persistence. If the `user` payload is
     * malformed or missing, the profile still carries whatever the JWT
     * exposes.
     *
     * @since 1.0.0
     */
    protected function buildProfile( ?string $idToken, ?string $userPayload ): AppleUserProfile
    {
        [ $sub, $email ] = $this->extractIdentity( $idToken );

        $firstName = null;
        $lastName  = null;
        $userEmail = null;

        if ( null !== $userPayload && '' !== $userPayload ) {
            $decoded = json_decode( $userPayload, true );

            if ( is_array( $decoded ) ) {
                if ( isset( $decoded[ 'name' ] ) && is_array( $decoded[ 'name' ] ) ) {
                    $firstName = isset( $decoded[ 'name' ][ 'firstName' ] )
                        ? (string) $decoded[ 'name' ][ 'firstName' ]
                        : null;
                    $lastName = isset( $decoded[ 'name' ][ 'lastName' ] )
                        ? (string) $decoded[ 'name' ][ 'lastName' ]
                        : null;
                }

                if ( isset( $decoded[ 'email' ] ) ) {
                    $userEmail = (string) $decoded[ 'email' ];
                }
            }
        }

        return new AppleUserProfile(
            sub:       (string) ( $sub ?? '' ),
            email:     $userEmail ?? $email,
            firstName: $firstName,
            lastName:  $lastName,
        );
    }

    /**
     * Decode the `sub` and `email` claims from Apple's id_token JWT.
     *
     * Signature verification is deferred; identity persistence relies on
     * the transport being the TLS-terminated Apple exchange.
     *
     * @since 1.0.0
     *
     * @return array{0: ?string, 1: ?string} [sub, email]
     */
    protected function extractIdentity( ?string $idToken ): array
    {
        if ( null === $idToken || '' === $idToken ) {
            return [ null, null ];
        }

        $parts = explode( '.', $idToken );

        if ( 3 !== count( $parts ) ) {
            return [ null, null ];
        }

        $payload = base64_decode( strtr( $parts[ 1 ], '-_', '+/' ), true );

        if ( false === $payload ) {
            return [ null, null ];
        }

        $claims = json_decode( $payload, true );

        if ( ! is_array( $claims ) ) {
            return [ null, null ];
        }

        return [
            isset( $claims[ 'sub' ] )   ? (string) $claims[ 'sub' ]   : null,
            isset( $claims[ 'email' ] ) ? (string) $claims[ 'email' ] : null,
        ];
    }
}
