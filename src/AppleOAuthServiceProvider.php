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

use ArtisanPackUI\AppleOAuth\Configuration\CmsSettingsDriver;
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

        $this->app->singleton( CmsSettingsDriver::class, function ( Application $app ): CmsSettingsDriver {
            return new CmsSettingsDriver( $app[ 'encrypter' ] );
        } );

        // Bound (not singleton) so `config('apple-oauth.driver')` is re-read
        // on each resolve; the concrete driver classes are singletons in
        // their own right and hold the per-request credential cache.
        $this->app->bind( ConfigurationRepository::class, function ( Application $app ): ConfigurationRepository {
            $driver = $app->make( ConfigRepository::class )->get( 'apple-oauth.driver', 'config' );

            return match ( $driver ) {
                'database' => $app->make( DatabaseDriver::class ),
                'cms'      => $app->make( CmsSettingsDriver::class ),
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

        $this->registerCmsSettings();
    }

    /**
     * Register OAuth-credential settings with the CMS framework when it is
     * installed. No-op otherwise — the base package must not hard-depend on
     * the CMS framework.
     *
     * Runs inside `$this->app->booted()` because the CMS-framework helpers
     * (apRegisterSetting / apGetSetting / apUpdateSetting) are declared from
     * that package's own boot() method, and Laravel's provider boot order is
     * not deterministic. If AppleOAuthServiceProvider happens to boot first,
     * registering directly from this class's boot() would silently skip the
     * setting keys and the CMS Settings UI would never expose them.
     *
     * @since 1.0.0
     */
    protected function registerCmsSettings(): void
    {
        $this->app->booted( function (): void {
            if ( ! function_exists( 'apRegisterSetting' ) ) {
                return;
            }

            $encrypter = $this->app[ 'encrypter' ];

            $trim = static function ( mixed $value ): ?string {
                if ( null === $value || '' === $value ) {
                    return null;
                }

                return trim( (string) $value );
            };

            // The private_key and client_secret settings are written by two
            // paths: `AppleOAuth::config()->save()` (driver → apUpdateSetting)
            // and the CMS Settings UI (operator → apUpdateSetting directly).
            // Owning encryption inside the sanitize callback makes both paths
            // write ciphertext, so the read-side decryption always sees an
            // encrypted value.
            $encryptSecret = static function ( mixed $value ) use ( $encrypter ): ?string {
                if ( null === $value || '' === $value ) {
                    return null;
                }

                return $encrypter->encryptString( (string) $value );
            };

            apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_ID, null, $trim );
            apRegisterSetting( CmsSettingsDriver::KEY_TEAM_ID, null, $trim );
            apRegisterSetting( CmsSettingsDriver::KEY_KEY_ID, null, $trim );
            apRegisterSetting( CmsSettingsDriver::KEY_PRIVATE_KEY, null, $encryptSecret );
            apRegisterSetting( CmsSettingsDriver::KEY_REDIRECT_URI, null, $trim );
            apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, null, $encryptSecret );
        } );
    }
}
