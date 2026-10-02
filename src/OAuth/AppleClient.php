<?php

/**
 * Stateless Sign in with Apple OAuth client.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Stateless OAuth primitives for talking to Apple directly.
 *
 * Nothing here touches the session or the database: the caller supplies
 * the credentials, `state`, `nonce` and scopes, and gets a
 * {@see TokenResponse} back. This is what an OAuth broker relays through,
 * and what {@see OAuthManager} and the token manager wrap to add session
 * handling and persistence.
 *
 * @since 1.1.0
 */
class AppleClient
{
    public const DEFAULT_AUTHORIZE_ENDPOINT = 'https://appleid.apple.com/auth/authorize';

    public const DEFAULT_TOKEN_ENDPOINT     = 'https://appleid.apple.com/auth/token';

    public const APPLE_ISSUER               = 'https://appleid.apple.com';

    /**
     * @since 1.1.0
     *
     * @param  AppleCredentials       $credentials        App credentials to authenticate with.
     * @param  HttpFactory            $http               HTTP client factory.
     * @param  ClientSecretGenerator  $clientSecret       ES256 `client_secret` signer, used when the credentials carry no static secret.
     * @param  string                 $authorizeEndpoint  Apple consent-screen endpoint.
     * @param  string                 $tokenEndpoint      Apple token endpoint.
     */
    public function __construct(
        protected AppleCredentials $credentials,
        protected HttpFactory $http,
        protected ClientSecretGenerator $clientSecret,
        protected string $authorizeEndpoint = self::DEFAULT_AUTHORIZE_ENDPOINT,
        protected string $tokenEndpoint = self::DEFAULT_TOKEN_ENDPOINT,
    ) {
    }

    /**
     * Build a client with the given (or configured) credentials and the
     * endpoints from `config/apple-oauth.php`.
     *
     * @since 1.1.0
     *
     * @param  AppleCredentials|ConfigurationRepository  $credentials  Explicit credentials, or a driver to read them from.
     */
    public static function make(
        AppleCredentials|ConfigurationRepository $credentials,
        HttpFactory $http,
        ClientSecretGenerator $clientSecret,
        ConfigRepository $config,
    ): self {
        if ( $credentials instanceof ConfigurationRepository ) {
            $credentials = AppleCredentials::fromRepository( $credentials );
        }

        return new self(
            $credentials,
            $http,
            $clientSecret,
            (string) $config->get( 'apple-oauth.endpoints.authorize', self::DEFAULT_AUTHORIZE_ENDPOINT ),
            (string) $config->get( 'apple-oauth.endpoints.token', self::DEFAULT_TOKEN_ENDPOINT ),
        );
    }

    /**
     * Verify a returned `state` against the one the caller generated.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the expected state is empty or does not match.
     */
    public static function verifyState( ?string $expected, string $returned ): void
    {
        if ( null === $expected || '' === $expected || ! hash_equals( $expected, $returned ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }
    }

    /**
     * Decode the claims from an id_token JWT without verifying its signature.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the JWT is malformed.
     *
     * @return array<string, mixed>
     */
    public static function decodeIdToken( string $idToken ): array
    {
        $parts = explode( '.', $idToken );

        if ( 3 !== count( $parts ) ) {
            throw new OAuthException( __( 'Apple id_token is malformed.' ) );
        }

        $payload = base64_decode( strtr( $parts[1], '-_', '+/' ), true );

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
     * The credentials this client authenticates with.
     *
     * @since 1.1.0
     */
    public function credentials(): AppleCredentials
    {
        return $this->credentials;
    }

    /**
     * Build the Apple consent URL.
     *
     * Always uses `response_mode=form_post`: Apple refuses to release the
     * one-shot `user` payload over the query or fragment response modes
     * whenever `name` or `email` is requested. Anything in `$parameters` is
     * added to (or overrides) the defaults.
     *
     * @since 1.1.0
     *
     * @param  string                 $state       Caller-generated state, echoed back on the callback.
     * @param  array<int, string>     $scopes      Scopes to request.
     * @param  string|null            $nonce       Nonce to bind into the id_token; omit to send none.
     * @param  array<string, string>  $parameters  Extra or overriding query parameters.
     *
     * @throws OAuthException When the client ID or redirect URI is missing.
     */
    public function authorizationUrl(
        string $state,
        array $scopes,
        ?string $nonce = null,
        array $parameters = [],
    ): string {
        $redirectUri = $this->requireRedirectUri();

        $params = [
            'client_id'     => $this->credentials->clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope'         => implode( ' ', $scopes ),
            'state'         => $state,
        ];

        if ( null !== $nonce ) {
            $params['nonce'] = $nonce;
        }

        return $this->authorizeEndpoint . '?' . http_build_query( array_merge( $params, $parameters ) );
    }

    /**
     * Exchange an authorization code for tokens without persisting them.
     *
     * Validates the id_token's `iss`, `aud`, `exp` and `sub` claims, plus
     * its `nonce` when one is expected. The `state` is not checked here —
     * verify it first with {@see self::verifyState()}.
     *
     * @since 1.1.0
     *
     * @param  string       $code         The `code` Apple form-posted to the redirect URI.
     * @param  string|null  $nonce        The nonce sent in the consent URL; null when none was sent.
     * @param  string|null  $userPayload  Raw JSON `user` field from Apple's first-authorization POST.
     *
     * @throws OAuthException When Apple rejects the exchange or the id_token fails validation.
     */
    public function exchangeCode( string $code, ?string $nonce = null, ?string $userPayload = null ): TokenResponse
    {
        $redirectUri = $this->requireRedirectUri();

        try {
            $clientSecret = $this->clientSecret();
            $this->assertSecureTokenEndpoint();
        } catch ( TokenRefreshException $e ) {
            throw new OAuthException( $e->getMessage(), $e->getError(), null, $e );
        }

        $response = $this->http->asForm()->post( $this->tokenEndpoint, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $this->credentials->clientId,
            'client_secret' => $clientSecret,
        ] );

        if ( ! $response->successful() ) {
            $error = $this->errorFrom( $response, 'exchange_failed' );

            throw new OAuthException(
                __( 'Apple code exchange failed: :error', [ 'error' => $error ] ),
                $error,
            );
        }

        $payload     = (array) $response->json();
        $accessToken = is_string( $payload['access_token'] ?? null ) ? $payload['access_token'] : '';
        $idToken     = is_string( $payload['id_token'] ?? null ) ? $payload['id_token'] : '';

        if ( '' === $accessToken ) {
            throw new OAuthException( __( 'Apple token response is missing access_token.' ), 'exchange_failed' );
        }

        if ( '' === $idToken ) {
            throw new OAuthException( __( 'Apple token response is missing id_token.' ), 'exchange_failed' );
        }

        $claims = self::decodeIdToken( $idToken );
        $this->validateIdTokenClaims( $claims, $nonce );

        return TokenResponse::fromApple( $payload, $this->buildProfile( $claims, $userPayload ) );
    }

    /**
     * Refresh an access token from a raw refresh-token string.
     *
     * Apple does not return a refresh token on refresh; the returned
     * response carries the one passed in (or a rotated one, if Apple ever
     * sends it). The profile is built from the id_token when Apple includes
     * one, and is null otherwise.
     *
     * @since 1.1.0
     *
     * @param  string  $refreshToken  The refresh token from the original exchange.
     * @param  string  $tokenType     Token type to keep when Apple does not report one.
     *
     * @throws TokenRefreshException When Apple rejects the refresh. `getError()` is `invalid_grant` for a revoked grant.
     */
    public function refresh( string $refreshToken, string $tokenType = 'Bearer' ): TokenResponse
    {
        if ( '' === $this->credentials->clientId ) {
            throw new TokenRefreshException( __( 'Apple OAuth credentials are not configured.' ), 'invalid_client' );
        }

        $clientSecret = $this->clientSecret();
        $this->assertSecureTokenEndpoint();

        $response = $this->http->asForm()->post( $this->tokenEndpoint, [
            'client_id'     => $this->credentials->clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ] );

        if ( ! $response->successful() ) {
            $error = $this->errorFrom( $response, 'refresh_failed' );

            throw new TokenRefreshException(
                __( 'Apple token refresh failed: :error', [ 'error' => $error ] ),
                $error,
            );
        }

        $payload = (array) $response->json();

        if ( ! is_string( $payload['access_token'] ?? null ) || '' === $payload['access_token'] ) {
            throw new TokenRefreshException( __( 'Apple token refresh response is missing access_token.' ), 'refresh_failed' );
        }

        return TokenResponse::fromApple( $payload, $this->profileFromRefresh( $payload ), $refreshToken, $tokenType );
    }

    /**
     * The `client_secret` to send: the static override when the credentials
     * carry one, else a cached ES256 JWT minted from the `.p8` key.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When neither a static secret nor complete signer inputs are available.
     */
    public function clientSecret(): string
    {
        $static = (string) ( $this->credentials->clientSecret ?? '' );

        if ( '' !== $static ) {
            return $static;
        }

        return $this->clientSecret->generateFor( $this->credentials );
    }

    /**
     * Enforce the id_token claim requirements Sign in with Apple documents:
     * issuer must be Apple, audience must match the Services ID, `exp` must
     * be in the future, `sub` must be non-empty, and — when one is
     * expected — `nonce` must match.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $claims
     * @param  string|null           $expectedNonce  Null skips the nonce check; an empty string always fails it.
     *
     * @throws OAuthException When a claim fails validation.
     */
    protected function validateIdTokenClaims( array $claims, ?string $expectedNonce ): void
    {
        $iss = isset( $claims['iss'] ) ? (string) $claims['iss'] : '';

        if ( self::APPLE_ISSUER !== $iss ) {
            throw new OAuthException(
                __( 'Apple id_token issuer mismatch: :iss', [ 'iss' => $iss ] ),
            );
        }

        $aud = $claims['aud'] ?? '';

        // Apple currently returns a scalar; guard against future array shapes.
        $aud = is_array( $aud ) ? array_map( 'strval', $aud ) : [ (string) $aud ];

        if ( ! in_array( $this->credentials->clientId, $aud, true ) ) {
            throw new OAuthException( __( 'Apple id_token audience mismatch.' ) );
        }

        if ( ! isset( $claims['exp'] ) || (int) $claims['exp'] <= time() ) {
            throw new OAuthException( __( 'Apple id_token is expired.' ) );
        }

        if ( null !== $expectedNonce ) {
            $tokenNonce = isset( $claims['nonce'] ) ? (string) $claims['nonce'] : '';

            if ( '' === $expectedNonce || ! hash_equals( $expectedNonce, $tokenNonce ) ) {
                throw new OAuthException( __( 'Apple id_token nonce mismatch.' ) );
            }
        }

        $sub = isset( $claims['sub'] ) ? (string) $claims['sub'] : '';

        if ( '' === $sub ) {
            throw new OAuthException( __( 'Apple id_token is missing sub claim.' ) );
        }
    }

    /**
     * Merge the id_token's `sub`/`email` claims with the one-shot `user`
     * payload Apple only releases on the first authorization.
     *
     * The id_token email is the canonical source — it comes from the
     * TLS-terminated server-to-server exchange with Apple. The one-shot
     * `user` form field rides through the user's browser on redirect and
     * is only trusted for the display name.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $claims       Validated id_token claims.
     * @param  string|null           $userPayload  Raw JSON `user` field from Apple's form POST.
     */
    protected function buildProfile( array $claims, ?string $userPayload ): AppleUserProfile
    {
        $firstName = null;
        $lastName  = null;

        if ( null !== $userPayload && '' !== $userPayload ) {
            $decoded = json_decode( $userPayload, true );

            if ( is_array( $decoded ) && isset( $decoded['name'] ) && is_array( $decoded['name'] ) ) {
                $firstName = isset( $decoded['name']['firstName'] ) ? (string) $decoded['name']['firstName'] : null;
                $lastName  = isset( $decoded['name']['lastName'] ) ? (string) $decoded['name']['lastName'] : null;
            }
        }

        return new AppleUserProfile(
            sub: (string) $claims['sub'],
            email: isset( $claims['email'] ) ? (string) $claims['email'] : null,
            firstName: $firstName,
            lastName: $lastName,
        );
    }

    /**
     * Best-effort profile from the id_token Apple includes on a refresh.
     *
     * Only `sub` and `email` are read; a missing or malformed token yields
     * null rather than failing an otherwise-good refresh.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $payload
     */
    protected function profileFromRefresh( array $payload ): ?AppleUserProfile
    {
        $idToken = is_string( $payload['id_token'] ?? null ) ? $payload['id_token'] : '';

        if ( '' === $idToken ) {
            return null;
        }

        try {
            $claims = self::decodeIdToken( $idToken );
        } catch ( OAuthException ) {
            return null;
        }

        $sub = isset( $claims['sub'] ) ? (string) $claims['sub'] : '';

        if ( '' === $sub ) {
            return null;
        }

        return new AppleUserProfile(
            sub: $sub,
            email: isset( $claims['email'] ) ? (string) $claims['email'] : null,
        );
    }

    /**
     * The configured redirect URI, required alongside the client ID.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the client ID or redirect URI is missing.
     */
    protected function requireRedirectUri(): string
    {
        $redirectUri = (string) ( $this->credentials->redirectUri ?? '' );

        if ( '' === $this->credentials->clientId || '' === $redirectUri ) {
            throw new OAuthException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        return $redirectUri;
    }

    /**
     * Refuse to send the client secret anywhere but an HTTPS token endpoint.
     *
     * @since 1.1.0
     *
     * @throws TokenRefreshException When the token endpoint is not HTTPS.
     */
    protected function assertSecureTokenEndpoint(): void
    {
        if ( 'https' !== strtolower( (string) parse_url( $this->tokenEndpoint, PHP_URL_SCHEME ) ) ) {
            throw new TokenRefreshException(
                __( 'Apple token endpoint must use HTTPS; refusing to transmit client_secret in cleartext.' ),
                'insecure_endpoint',
            );
        }
    }

    /**
     * Resolve the OAuth error code from a failed response.
     *
     * @since 1.1.0
     */
    protected function errorFrom( Response $response, string $fallback ): string
    {
        $body = $response->json();

        return is_array( $body ) && is_scalar( $body['error'] ?? null ) ? (string) $body['error'] : $fallback;
    }
}
