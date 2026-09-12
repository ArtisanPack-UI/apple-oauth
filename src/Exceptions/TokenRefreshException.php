<?php

/**
 * Token refresh exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Exceptions;

use RuntimeException;

/**
 * Thrown when an Apple access token cannot be refreshed.
 *
 * The connection is marked disconnected before this exception is thrown when
 * Apple signals `invalid_grant` (the refresh token has been revoked or the
 * user removed the Sign in with Apple grant for this app) or when no refresh
 * token was ever stored for the connection.
 *
 * @since 1.0.0
 */
class TokenRefreshException extends RuntimeException
{
}
