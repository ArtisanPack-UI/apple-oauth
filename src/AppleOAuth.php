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

use ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator;
use ArtisanPackUI\AppleOAuth\OAuth\OAuthManager;
use ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry;
use ArtisanPackUI\AppleOAuth\Tokens\TokenManager;

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
}
