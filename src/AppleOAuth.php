<?php

/**
 * Main AppleOAuth class.
 *
 * This is the main class for the package, accessed via the 'apple_oauth' helper
 * function or the AppleOAuth facade.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth;

use ArtisanPackUI\AppleOAuth\Broker\BrokerClient;
use ArtisanPackUI\AppleOAuth\Broker\BrokerCredentials;
use ArtisanPackUI\AppleOAuth\OAuth\AppleClient;
use ArtisanPackUI\AppleOAuth\OAuth\AppleCredentials;
use ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator;
use ArtisanPackUI\AppleOAuth\OAuth\OAuthManager;
use ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry;
use ArtisanPackUI\AppleOAuth\Tokens\TokenManager;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Facade entry point for the Apple OAuth broker.
 *
 * Provides accessors for the OAuth authorization-code flow manager and,
 * over the course of the v1.0 milestone, the token store, scope registry,
 * and configuration repository.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */
class AppleOAuth
{
    public function __construct(
        protected OAuthManager $oauth,
        protected ClientSecretGenerator $clientSecret,
        protected TokenManager $tokens,
        protected ScopeRegistry $scopes,
        protected AppleOAuthManager $manager,
        protected ?HttpFactory $http = null,
    ) {
    }

    /**
     * Access the OAuth authorization-code flow manager.
     *
     * @since 1.0.0
     */
    public function oauth(): OAuthManager
    {
        return $this->oauth;
    }

    /**
     * Access the consumer-facing manager (authorized HTTP requests +
     * the token-provider seam) downstream Apple API clients build on.
     *
     * @since 1.0.0
     */
    public function manager(): AppleOAuthManager
    {
        return $this->manager;
    }

    /**
     * Access the ES256 client-secret JWT generator.
     *
     * @since 1.0.0
     */
    public function clientSecret(): ClientSecretGenerator
    {
        return $this->clientSecret;
    }

    /**
     * Access the encrypted token store and refresh manager.
     *
     * @since 1.0.0
     */
    public function tokens(): TokenManager
    {
        return $this->tokens;
    }

    /**
     * Access the scope registry.
     *
     * @since 1.0.0
     */
    public function scopes(): ScopeRegistry
    {
        return $this->scopes;
    }

    /**
     * A stateless Sign in with Apple client.
     *
     * With no arguments it uses the configured credential driver. Pass
     * explicit credentials to relay for another app, as an OAuth broker
     * does; the client never touches the session or the database.
     *
     * @since 1.1.0
     */
    public function client( ?AppleCredentials $credentials = null ): AppleClient
    {
        return $this->oauth->client( $credentials );
    }

    /**
     * A client for the OAuth broker, from explicit or configured credentials.
     *
     * @since 1.1.0
     *
     * @throws Exceptions\OAuthException When no credentials are passed and none are configured.
     */
    public function broker( ?BrokerCredentials $credentials = null ): BrokerClient
    {
        if ( null !== $credentials ) {
            return new BrokerClient( $credentials, $this->http ?? app( HttpFactory::class ) );
        }

        return $this->oauth->brokerClient();
    }

    /**
     * Whether the package is in broker client mode (`apple-oauth.mode` = `broker`).
     *
     * @since 1.1.0
     */
    public function usesBroker(): bool
    {
        return $this->oauth->usesBroker();
    }
}
