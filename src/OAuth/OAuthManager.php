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

use ArtisanPackUI\AppleOAuth\Broker\BrokerClient;
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\AppleOAuth\Exceptions\OAuthException;
use ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
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
 * The Apple calls themselves go through the stateless {@see AppleClient},
 * or through {@see BrokerClient} when `apple-oauth.mode` is `broker`.
 *
 * @since 1.0.0
 */
class OAuthManager
{
    protected const SESSION_STATE   = 'apple-oauth.state';

    protected const SESSION_NONCE   = 'apple-oauth.nonce';

    protected const SESSION_USER_ID = 'apple-oauth.user_id';

    protected const APPLE_ISSUER    = AppleClient::APPLE_ISSUER;

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
     * In broker mode this is the signed broker `/authorize` link instead;
     * the broker runs the Apple leg (including the nonce) itself.
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
        $scopes = $override ?? $this->scopes->all();

        if ( $this->usesBroker() ) {
            return $this->buildBrokerAuthorizationUrl( $userId, $scopes );
        }

        $client      = $this->client();
        $credentials = $client->credentials();

        if ( '' === $credentials->clientId || '' === (string) $credentials->redirectUri ) {
            throw new OAuthException( __( 'Apple OAuth credentials are not configured.' ) );
        }

        $state = Str::random( 40 );
        $nonce = Str::random( 40 );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->put( self::SESSION_NONCE, $nonce );
        $this->session->put( self::SESSION_USER_ID, $userId );

        return $client->authorizationUrl( $state, $scopes, $nonce );
    }

    /**
     * Handle the callback Apple form-posts to the redirect URI (or, in
     * broker mode, the GET the broker sends back to the return URL).
     *
     * Verifies the returned `state` against the session, exchanges the
     * authorization `code` for tokens, and merges any first-authorization
     * `user` payload (name/email) into the returned profile. The `user`
     * argument, when present, is the raw JSON string Apple posts back —
     * it is provided exactly once and must be captured immediately. It is
     * ignored in broker mode, where the broker relays the name as
     * `account_name`.
     *
     * In direct mode the id_token's `iss`, `aud`, `exp`, and `nonce` claims
     * are validated against the stored nonce and configured client. Full
     * JWKS-backed signature verification is not performed; identity trust
     * rests on the TLS-terminated server-to-server exchange with Apple (or
     * the broker) plus these claim checks.
     *
     * @since 1.0.0
     *
     * @param  string       $code           Authorization code from Apple, or the broker's one-time code.
     * @param  string       $returnedState  The state Apple (or the broker) echoed back.
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

        AppleClient::verifyState( null === $storedState ? null : (string) $storedState, $returnedState );

        if ( null === $userId ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $tokens = $this->usesBroker()
            ? $this->brokerClient()->exchangeCode( $code )
            : $this->client()->exchangeCode( $code, (string) $storedNonce, $userPayload );

        return $tokens->withUserId( $userId );
    }

    /**
     * Stateless Apple client, from explicit credentials or the configured driver.
     *
     * @since 1.1.0
     */
    public function client( ?AppleCredentials $credentials = null ): AppleClient
    {
        return AppleClient::make( $credentials ?? $this->credentials, $this->http, $this->clientSecret, $this->config );
    }

    /**
     * Broker client built from the configured broker credentials.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the broker is not configured.
     */
    public function brokerClient(): BrokerClient
    {
        return BrokerClient::fromConfig( $this->config, $this->http );
    }

    /**
     * Whether the package is in broker client mode.
     *
     * @since 1.1.0
     */
    public function usesBroker(): bool
    {
        return BrokerClient::isEnabled( $this->config );
    }

    /**
     * Whether a license `renew_url` from the broker's return is safe to show
     * the user.
     *
     * Only true in broker mode, for URLs on the broker's own host. The
     * `renew_url` arrives on the return URL's query string, so anyone can
     * forge it; check it here before linking to it.
     *
     * @since 1.1.0
     */
    public function isTrustedRenewUrl( ?string $url ): bool
    {
        if ( ! $this->usesBroker() ) {
            return false;
        }

        try {
            return $this->brokerClient()->isTrustedRenewUrl( $url );
        } catch ( OAuthException ) {
            return false;
        }
    }

    /**
     * Build the signed broker `/authorize` URL. The broker runs the nonce
     * with Apple itself, so only state and the user are kept in the session.
     *
     * @since 1.1.0
     *
     * @param  array<int, string>  $scopes  Scopes to request.
     *
     * @throws OAuthException When the broker or its return URL is not configured.
     */
    protected function buildBrokerAuthorizationUrl( int|string $userId, array $scopes ): string
    {
        $broker    = $this->brokerClient();
        $returnUrl = (string) $this->config->get( 'apple-oauth.broker.return_url', '' );

        if ( '' === $returnUrl ) {
            throw new OAuthException( __( 'Set apple-oauth.broker.return_url to use the Apple OAuth broker.' ) );
        }

        $state = Str::random( 40 );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->forget( self::SESSION_NONCE );
        $this->session->put( self::SESSION_USER_ID, $userId );

        return $broker->authorizationUrl( $state, $returnUrl, $scopes );
    }
}
