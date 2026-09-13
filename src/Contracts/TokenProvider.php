<?php

/**
 * Apple OAuth access-token provider contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Contracts;

use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

/**
 * Hands a downstream package a currently-valid Apple OAuth access token.
 *
 * This is the stable seam other ArtisanPack packages (a calendar client, a
 * mail client) resolve out of the container to authorize their Apple API
 * calls. Implementations refresh the underlying grant transparently when the
 * stored token has expired, so a caller never has to reason about token
 * lifetimes — they ask for a token, they get one they can use immediately.
 *
 * Binding the contract (rather than referencing {@see AppleOAuth} directly)
 * lets consumers depend on a small surface here and lets tests swap in a
 * fake provider without booting the OAuth machinery.
 *
 * @since 1.0.0
 */
interface TokenProvider
{
    /**
     * Return a currently-valid OAuth access token for the connection.
     *
     * The implementation must refresh transparently when the stored access
     * token has expired; a returned token is safe to use as an
     * `Authorization: Bearer <token>` header value against Apple's APIs.
     *
     * @since 1.0.0
     *
     * @throws TokenRefreshException When the connection is disconnected or
     *                               the grant cannot be refreshed.
     */
    public function accessTokenFor( AppleConnection $connection ): string;
}
