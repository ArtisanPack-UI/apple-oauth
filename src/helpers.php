<?php

/**
 * AppleOAuth helper functions.
 *
 * This file contains global helper functions for the package.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

use ArtisanPackUI\AppleOAuth\AppleOAuth;

if ( ! function_exists( 'apple_oauth' ) ) {
    /**
     * Get the AppleOAuth instance.
     *
     * @since 1.0.0
     *
     * @return AppleOAuth
     */
    function apple_oauth(): AppleOAuth
    {
        return app( 'apple-oauth' );
    }
}
