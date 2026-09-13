<?php

/**
 * Apple OAuth consumer manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth;

use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Consumer-facing manager for downstream Apple API clients.
 *
 * Wraps the token-refresh + HTTP-request wiring a caller would otherwise
 * reassemble by hand: ask a {@see TokenProvider} for a currently-valid
 * bearer token, put it on a Laravel {@see PendingRequest}, hand the request
 * back. Downstream packages (calendar, mail) build on top of it and never
 * touch the OAuth internals — the manager is the only class they need to
 * type-hint from this package to make an authorized request.
 *
 * @since 1.0.0
 */
class AppleOAuthManager
{
    public function __construct(
        protected TokenProvider $tokens,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Build a Laravel HTTP client pre-authorized with the connection's
     * current access token.
     *
     * A downstream caller uses it like the framework's own factory:
     *
     *     $response = AppleOAuth::manager()->request( $connection )
     *         ->acceptJson()
     *         ->get( 'https://caldav.icloud.com/...' );
     *
     * The token is resolved through {@see TokenProvider::accessTokenFor()},
     * which refreshes transparently when the stored token has expired, so
     * the returned request always carries a token that is safe to use
     * immediately. Any refresh-failure exception propagates out of this
     * method rather than the eventual HTTP call, giving callers a single
     * point to catch a dead OAuth grant.
     *
     * @since 1.0.0
     *
     * @throws Exceptions\TokenRefreshException
     *         When the connection cannot yield a usable access token.
     */
    public function request( AppleConnection $connection ): PendingRequest
    {
        $token = $this->tokens->accessTokenFor( $connection );

        return $this->http->withToken( $token );
    }

    /**
     * Access the underlying token provider.
     *
     * Handed out for consumers that need only the token string (e.g. to
     * hand to a third-party SDK that owns its own HTTP client).
     *
     * @since 1.0.0
     */
    public function tokens(): TokenProvider
    {
        return $this->tokens;
    }
}
