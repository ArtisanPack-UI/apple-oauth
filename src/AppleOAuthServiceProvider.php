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

use ArtisanPackUI\AppleOAuth\Configuration\ConfigDriver;
use ArtisanPackUI\AppleOAuth\Configuration\DatabaseDriver;
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\AppleOAuth\OAuth\ClientSecretGenerator;
use ArtisanPackUI\AppleOAuth\OAuth\OAuthManager;
use ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry;
use ArtisanPackUI\AppleOAuth\Tokens\TokenManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton( ConfigDriver::class, function ( Application $app ): ConfigDriver {
            return new ConfigDriver( $app->make( ConfigRepository::class ) );
        } );

        $this->app->singleton( DatabaseDriver::class, function ( Application $app ): DatabaseDriver {
            return new DatabaseDriver(
                $app[ 'db' ]->connection(),
                $app[ 'encrypter' ],
            );
        } );

        // Bound (not singleton) so `config('apple-oauth.driver')` is re-read
        // on each resolve; the concrete driver classes are singletons in
        // their own right and hold the per-request credential cache.
        $this->app->bind( ConfigurationRepository::class, function ( Application $app ): ConfigurationRepository {
            $driver = $app->make( ConfigRepository::class )->get( 'apple-oauth.driver', 'config' );

            return match ( $driver ) {
                'database' => $app->make( DatabaseDriver::class ),
                default    => $app->make( ConfigDriver::class ),
            };
        } );

        $this->app->singleton( ClientSecretGenerator::class, function ( $app ) {
            return new ClientSecretGenerator(
                $app->make( ConfigRepository::class ),
                $app->make( CacheRepository::class ),
                $app->make( ConfigurationRepository::class ),
            );
        } );

        $this->app->singleton( ScopeRegistry::class );

        $this->app->singleton( OAuthManager::class, function ( $app ) {
            return new OAuthManager(
                $app->make( ConfigRepository::class ),
                $app->make( 'session.store' ),
                $app->make( HttpFactory::class ),
                $app->make( ClientSecretGenerator::class ),
                $app->make( ScopeRegistry::class ),
                $app->make( ConfigurationRepository::class ),
            );
        } );

        $this->app->singleton( TokenManager::class, function ( $app ) {
            return new TokenManager(
                $app->make( ConfigRepository::class ),
                $app->make( HttpFactory::class ),
                $app->make( ClientSecretGenerator::class ),
                $app->make( ConfigurationRepository::class ),
            );
        } );

        $this->app->singleton( 'apple-oauth', function ( $app ) {
            return new AppleOAuth(
                $app->make( OAuthManager::class ),
                $app->make( ClientSecretGenerator::class ),
                $app->make( TokenManager::class ),
                $app->make( ScopeRegistry::class ),
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
        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );

        if ( $this->app->runningInConsole() ) {
            $this->publishes(
                [ __DIR__ . '/../config/apple-oauth.php' => config_path( 'apple-oauth.php' ) ],
                'apple-oauth-config',
            );

            $this->publishes(
                [ __DIR__ . '/../database/migrations' => database_path( 'migrations' ) ],
                'apple-oauth-migrations',
            );
        }
    }
}
