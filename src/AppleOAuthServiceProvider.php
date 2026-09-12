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

use ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator;
use ArtisanPackUI\AppleOAuth\OAuth\OAuthManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
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
        $this->mergeConfigFrom(
            __DIR__ . '/../config/apple-oauth.php',
            'apple-oauth',
        );

        $this->app->singleton( ClientSecretGenerator::class, function ( $app ) {
            return new ClientSecretGenerator(
                $app->make( ConfigRepository::class ),
                $app->make( CacheRepository::class ),
            );
        } );

        $this->app->singleton( OAuthManager::class, function ( $app ) {
            return new OAuthManager(
                $app->make( ConfigRepository::class ),
                $app->make( 'session.store' ),
                $app->make( HttpFactory::class ),
                $app->make( ClientSecretGenerator::class ),
            );
        } );

        $this->app->singleton( 'apple-oauth', function ( $app ) {
            return new AppleOAuth(
                $app->make( OAuthManager::class ),
                $app->make( ClientSecretGenerator::class ),
            );
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
        if ( $this->app->runningInConsole() ) {
            $this->publishes(
                [ __DIR__ . '/../config/apple-oauth.php' => config_path( 'apple-oauth.php' ) ],
                'apple-oauth-config',
            );
        }
    }
}
