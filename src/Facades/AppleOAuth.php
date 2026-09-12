<?php

/**
 * AppleOAuth Facade.
 *
 * Provides static access to the AppleOAuth class.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * AppleOAuth Facade.
 *
 * @see \ArtisanPackUI\AppleOAuth\AppleOAuth
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */
class AppleOAuth extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'apple-oauth';
    }
}
