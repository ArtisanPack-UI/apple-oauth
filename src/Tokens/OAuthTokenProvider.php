<?php

/**
 * OAuth-backed access-token provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Tokens;

use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

/**
 * Resolves a connection's access token through {@see TokenManager}.
 *
 * The bound implementation of {@see TokenProvider}. Delegates to
 * `TokenManager::getValidAccessToken()`, which handles the expiry check and
 * transparent refresh, so a caller receives a token that is safe to use
 * immediately. This class exists so downstream consumers depend on the
 * small {@see TokenProvider} contract rather than reaching into the
 * package's OAuth internals.
 *
 * @since 1.0.0
 */
class OAuthTokenProvider implements TokenProvider
{
    public function __construct( protected TokenManager $tokens )
    {
    }

    public function accessTokenFor( AppleConnection $connection ): string
    {
        return $this->tokens->getValidAccessToken( $connection );
    }
}
