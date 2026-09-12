<?php

/**
 * OAuth-related exception.
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
 * Thrown when Apple OAuth negotiation fails.
 *
 * @since 1.0.0
 */
class OAuthException extends RuntimeException
{
}
