<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Configuration\CmsSettingsDriver;
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

beforeEach( function (): void {
    // Reset the stub-backed CMS settings store between tests. Sanitize
    // callbacks were registered when the AppleOAuth service provider booted
    // via `$this->app->booted()` (see tests/Support/CmsSettingsStub.php).
    $GLOBALS[ '__cms_settings_stub_values' ] = [];

    config()->set( 'apple-oauth.driver', 'cms' );
    app( CmsSettingsDriver::class )->flush();
} );

it( 'resolves the CmsSettingsDriver when driver=cms', function (): void {
    expect( app( ConfigurationRepository::class ) )
        ->toBeInstanceOf( CmsSettingsDriver::class );
} );

it( 'writes credentials through apUpdateSetting and reads them back', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid-from-cms',
        'team_id'       => 'TEAM123',
        'key_id'        => 'KEY456',
        'private_key'   => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
        'redirect_uri'  => 'https://example.test/cb',
        'client_secret' => 'super-secret',
    ] );

    $driver->flush();

    expect( $driver->getClientId() )->toBe( 'cid-from-cms' );
    expect( $driver->getTeamId() )->toBe( 'TEAM123' );
    expect( $driver->getKeyId() )->toBe( 'KEY456' );
    expect( $driver->getPrivateKey() )->toBe( "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----" );
    expect( $driver->getRedirectUri() )->toBe( 'https://example.test/cb' );
    expect( $driver->getClientSecret() )->toBe( 'super-secret' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'stores the private key and client secret encrypted at rest', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'team_id'       => 'TEAM',
        'key_id'        => 'KEY',
        'private_key'   => 'plaintext-key-material',
        'redirect_uri'  => 'https://example.test/cb',
        'client_secret' => 'plaintext-secret',
    ] );

    $rawKey    = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_PRIVATE_KEY ];
    $rawSecret = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ];

    expect( $rawKey )->not->toBe( 'plaintext-key-material' );
    expect( $rawSecret )->not->toBe( 'plaintext-secret' );
    expect( app( 'encrypter' )->decryptString( $rawKey ) )->toBe( 'plaintext-key-material' );
    expect( app( 'encrypter' )->decryptString( $rawSecret ) )->toBe( 'plaintext-secret' );
} );

it( 'is configured with signer inputs but no client_secret', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'team_id'       => 'TEAM',
        'key_id'        => 'KEY',
        'private_key'   => 'key-material',
        'redirect_uri'  => 'https://example.test/cb',
        'client_secret' => null,
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'is not configured when signer inputs are missing and no client_secret', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'    => 'cid',
        'redirect_uri' => 'https://example.test/cb',
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'is not configured when client_id or redirect_uri are missing', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => null,
        'redirect_uri'  => null,
        'client_secret' => 'pre-minted',
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'encrypts secrets written directly through apUpdateSetting (Settings UI path)', function (): void {
    // Simulates an operator typing credentials into the CMS Settings admin
    // UI: apUpdateSetting is called with plaintext. The sanitize callbacks
    // registered by AppleOAuthServiceProvider must encrypt the sensitive
    // ones, otherwise the driver's decryption step later blows up and
    // isConfigured() flips to false with no visible reason.
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_ID, 'ui-cid' );
    apUpdateSetting( CmsSettingsDriver::KEY_TEAM_ID, 'ui-team' );
    apUpdateSetting( CmsSettingsDriver::KEY_KEY_ID, 'ui-key' );
    apUpdateSetting( CmsSettingsDriver::KEY_PRIVATE_KEY, 'ui-typed-key' );
    apUpdateSetting( CmsSettingsDriver::KEY_REDIRECT_URI, 'https://example.test/cb' );
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, 'ui-typed-secret' );

    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );
    $driver->flush();

    $rawKey    = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_PRIVATE_KEY ];
    $rawSecret = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ];
    expect( $rawKey )->not->toBe( 'ui-typed-key' );
    expect( $rawSecret )->not->toBe( 'ui-typed-secret' );
    expect( app( 'encrypter' )->decryptString( $rawKey ) )->toBe( 'ui-typed-key' );
    expect( app( 'encrypter' )->decryptString( $rawSecret ) )->toBe( 'ui-typed-secret' );

    expect( $driver->getPrivateKey() )->toBe( 'ui-typed-key' );
    expect( $driver->getClientSecret() )->toBe( 'ui-typed-secret' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'trims whitespace from non-sensitive settings via sanitize callbacks', function (): void {
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_ID, '  padded-cid  ' );

    expect( $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_ID ] )
        ->toBe( 'padded-cid' );
} );

it( 'treats undecryptable ciphertext as unconfigured and logs a warning', function (): void {
    // Simulate an APP_KEY rotation: write a value encrypted with a
    // different key, so the current encrypter can't decrypt it.
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_ID, 'cid' );
    apUpdateSetting( CmsSettingsDriver::KEY_REDIRECT_URI, 'https://example.test/cb' );

    // Overwrite the stored ciphertext with a garbage value that will fail
    // to decrypt.
    $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ] = 'not-a-valid-cipher';

    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );
    $driver->flush();

    expect( $driver->getClientSecret() )->toBeNull();
} );
