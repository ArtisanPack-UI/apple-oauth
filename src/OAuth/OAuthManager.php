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

use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry;
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

    protected const APPLE_ISSUER    = 'https://appleid.apple.com';

    public function __construct(
        protected ConfigRepository $config,
        protected Session $session,
        protected HttpFactory $http,
        protected ClientSecretGenerator $clientSecret,
        protected ScopeRegistry $scopes,
        protected ConfigurationRepository $credentials,
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
     * @param  array<int, string>|null   $override  Explicit scopes; defaults to the registry union.
     *
     * @throws OAuthException When required credentials are missing.
     */
    public function authorizationUrl( int|string $userId, ?array $override = null ): string
    {
        $clientId    = (string) ( $this->credentials->getClientId() ?? '' );
        $redirectUri = (string) ( $this->credentials->getRedirectUri() ?? '' );

        if ( '' === $clientId || '' === $redirectUri ) {
            throw new OAuthException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        $scopes = $override ?? $this->scopes->all();

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
     * The id_token's `iss`, `aud`, `exp`, and `nonce` claims are validated
     * against the stored nonce and configured client. Full JWKS-backed
     * signature verification is deferred to #3 (JWT signer) and #4
     * (encrypted token store); until then, identity trust rests on the
     * TLS-terminated server-to-server exchange with Apple plus these
     * claim checks.
     *
     * @since 1.0.0
     *
     * @param  string       $code           Authorization code from Apple.
     * @param  string       $returnedState  The state Apple echoed back.
     * @param  string|null  $userPayload    Raw JSON `user` field from the first-authorization POST body.
     *
     * @throws OAuthException On state mismatch, missing session context, non-HTTPS
     *                        token endpoint, token-exchange failure, missing
     *                        required token fields, or id_token claim mismatch.
     */
    public function handleCallback( string $code, string $returnedState, ?string $userPayload = null ): TokenResponse
    {
        $storedState = $this->session->pull( self::SESSION_STATE );
        $storedNonce = $this->session->pull( self::SESSION_NONCE );
        $userId      = $this->session->pull( self::SESSION_USER_ID );

        if ( empty( $storedState ) || ! hash_equals( (string) $storedState, $returnedState ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }

        if ( null === $userId ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $clientId     = (string) ( $this->credentials->getClientId() ?? '' );
        $redirectUri  = (string) ( $this->credentials->getRedirectUri() ?? '' );
        $clientSecret = (string) ( $this->credentials->getClientSecret() ?? '' );

        if ( '' === $clientId || '' === $redirectUri ) {
            throw new OAuthException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        if ( '' === $clientSecret ) {
            $clientSecret = $this->clientSecret->generate();
        }

        $endpoint = (string) $this->config->get(
            'apple-oauth.endpoints.token',
            'https://appleid.apple.com/auth/token',
        );

        if ( 'https' !== strtolower( (string) parse_url( $endpoint, PHP_URL_SCHEME ) ) ) {
            throw new OAuthException(
                __( 'Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.' ),
            );
        }

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

        $accessToken = isset( $payload[ 'access_token' ] ) ? (string) $payload[ 'access_token' ] : '';
        $idToken     = isset( $payload[ 'id_token' ] )     ? (string) $payload[ 'id_token' ]     : '';

        if ( '' === $accessToken ) {
            throw new OAuthException( __( 'Apple token response is missing access_token.' ) );
        }

        if ( '' === $idToken ) {
            throw new OAuthException( __( 'Apple token response is missing id_token.' ) );
        }

        $claims = $this->decodeClaims( $idToken );
        $this->validateIdTokenClaims( $claims, $clientId, (string) $storedNonce );

        $expiresAt = isset( $payload[ 'expires_in' ] )
            ? Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] )
            : null;

        $profile = $this->buildProfile( $claims, $userPayload );

        return new TokenResponse(
            userId:       $userId,
            accessToken:  $accessToken,
            refreshToken: isset( $payload[ 'refresh_token' ] ) ? (string) $payload[ 'refresh_token' ] : null,
            idToken:      $idToken,
            tokenType:    (string) ( $payload[ 'token_type' ] ?? 'Bearer' ),
            expiresAt:    $expiresAt,
            profile:      $profile,
        );
    }

    /**
     * Merge the id_token's `sub`/`email` claims with the one-shot `user`
     * payload Apple only releases on the first authorization.
     *
     * The id_token email is the canonical source — it comes from the
     * TLS-terminated server-to-server exchange with Apple. The one-shot
     * `user` form field rides through the user's browser on redirect and
     * is only trusted for the display name; if its `email` disagrees with
     * the id_token claim it is discarded.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $claims       Validated id_token claims.
     * @param  string|null           $userPayload  Raw JSON `user` field from Apple's form POST.
     */
    protected function buildProfile( array $claims, ?string $userPayload ): AppleUserProfile
    {
        $sub   = (string) $claims[ 'sub' ];
        $email = isset( $claims[ 'email' ] ) ? (string) $claims[ 'email' ] : null;

        $firstName = null;
        $lastName  = null;

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
            }
        }

        return new AppleUserProfile(
            sub:       $sub,
            email:     $email,
            firstName: $firstName,
            lastName:  $lastName,
        );
    }

    /**
     * Decode the id_token JWT claims payload.
     *
     * Signature verification is deferred to #3/#4; identity trust rests on
     * the TLS-terminated server-to-server exchange plus the claim checks
     * in {@see validateIdTokenClaims()}.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the JWT is malformed.
     *
     * @return array<string, mixed>
     */
    protected function decodeClaims( string $idToken ): array
    {
        $parts = explode( '.', $idToken );

        if ( 3 !== count( $parts ) ) {
            throw new OAuthException( __( 'Apple id_token is malformed.' ) );
        }

        $payload = base64_decode( strtr( $parts[ 1 ], '-_', '+/' ), true );

        if ( false === $payload ) {
            throw new OAuthException( __( 'Apple id_token payload is not valid base64.' ) );
        }

        $claims = json_decode( $payload, true );

        if ( ! is_array( $claims ) ) {
            throw new OAuthException( __( 'Apple id_token payload is not a JSON object.' ) );
        }

        return $claims;
    }

    /**
     * Enforce the id_token claim requirements Sign in with Apple documents:
     * issuer must be Apple, audience must match our Services ID, exp must
     * be in the future, nonce must match the value stashed in the session,
     * and sub must be a non-empty string.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $claims
     */
    protected function validateIdTokenClaims( array $claims, string $expectedAud, string $expectedNonce ): void
    {
        $iss = isset( $claims[ 'iss' ] ) ? (string) $claims[ 'iss' ] : '';

        if ( self::APPLE_ISSUER !== $iss ) {
            throw new OAuthException(
                __( 'Apple id_token issuer mismatch: :iss', [ 'iss' => $iss ] ),
            );
        }

        $aud = $claims[ 'aud' ] ?? '';

        // Apple currently returns a scalar; guard against future array shapes.
        if ( is_array( $aud ) ) {
            $aud = array_map( 'strval', $aud );
        } else {
            $aud = [ (string) $aud ];
        }

        if ( ! in_array( $expectedAud, $aud, true ) ) {
            throw new OAuthException( __( 'Apple id_token audience mismatch.' ) );
        }

        if ( ! isset( $claims[ 'exp' ] ) || (int) $claims[ 'exp' ] <= time() ) {
            throw new OAuthException( __( 'Apple id_token is expired.' ) );
        }

        $tokenNonce = isset( $claims[ 'nonce' ] ) ? (string) $claims[ 'nonce' ] : '';

        if ( '' === $expectedNonce || ! hash_equals( $expectedNonce, $tokenNonce ) ) {
            throw new OAuthException( __( 'Apple id_token nonce mismatch.' ) );
        }

        $sub = isset( $claims[ 'sub' ] ) ? (string) $claims[ 'sub' ] : '';

        if ( '' === $sub ) {
            throw new OAuthException( __( 'Apple id_token is missing sub claim.' ) );
        }
    }
}
