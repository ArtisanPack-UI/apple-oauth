<?php

/**
 * AppleOAuth service provider.
 *
 * Bootstraps the AppleOAuth package by registering services and bindings.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the AppleOAuth package.
 *
 * Bootstraps the AppleOAuth package by registering services and bindings.
 * Extend this class with the package's configuration, migrations,
 * routes, views, and other service registrations.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */
class AppleOAuthServiceProvider extends ServiceProvider
{
    /**
     * Registers any application services.
     *
     * Binds the AppleOAuth class as a singleton in the container.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton( 'apple-oauth', function ( $app ) {
            return new AppleOAuth();
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        // Add package bootstrapping here.
    }
}
