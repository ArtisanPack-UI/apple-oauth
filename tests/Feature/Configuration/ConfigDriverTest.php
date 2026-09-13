<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Configuration\ConfigDriver;
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

beforeEach( function (): void {
    config()->set( 'apple-oauth.driver', 'config' );
} );

it( 'is the default driver bound to the contract', function (): void {
    expect( app( ConfigurationRepository::class ) )->toBeInstanceOf( ConfigDriver::class );
} );

it( 'reads every credential from the config repository', function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.team_id', 'TEAM12345' );
    config()->set( 'apple-oauth.key_id', 'KEY67890' );
    config()->set( 'apple-oauth.private_key', '-----BEGIN EC PRIVATE KEY-----abc-----END EC PRIVATE KEY-----' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );
    config()->set( 'apple-oauth.client_secret', 'preminted.jwt.here' );

    /** @var ConfigDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBe( 'com.example.service' );
    expect( $driver->getTeamId() )->toBe( 'TEAM12345' );
    expect( $driver->getKeyId() )->toBe( 'KEY67890' );
    expect( $driver->getPrivateKey() )->toBe( '-----BEGIN EC PRIVATE KEY-----abc-----END EC PRIVATE KEY-----' );
    expect( $driver->getRedirectUri() )->toBe( 'https://example.test/callback' );
    expect( $driver->getClientSecret() )->toBe( 'preminted.jwt.here' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'reports configured when only a pre-minted client_secret is present', function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );
    config()->set( 'apple-oauth.client_secret', 'preminted.jwt.here' );
    config()->set( 'apple-oauth.team_id', null );
    config()->set( 'apple-oauth.key_id', null );
    config()->set( 'apple-oauth.private_key', null );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeTrue();
} );

it( 'reports configured when the full signer inputs are present without a static client_secret', function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );
    config()->set( 'apple-oauth.team_id', 'TEAM12345' );
    config()->set( 'apple-oauth.key_id', 'KEY67890' );
    config()->set( 'apple-oauth.private_key', '-----BEGIN EC PRIVATE KEY-----abc-----END EC PRIVATE KEY-----' );
    config()->set( 'apple-oauth.client_secret', null );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeTrue();
} );

it( 'reports unconfigured when client_id or redirect_uri is missing', function (): void {
    config()->set( 'apple-oauth.client_id', null );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );
    config()->set( 'apple-oauth.client_secret', 'preminted.jwt.here' );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeFalse();

    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.redirect_uri', null );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeFalse();
} );

it( 'reports unconfigured when neither a client_secret nor the full signer inputs are present', function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.service' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );
    config()->set( 'apple-oauth.client_secret', null );
    config()->set( 'apple-oauth.team_id', 'TEAM12345' );
    config()->set( 'apple-oauth.key_id', null );
    config()->set( 'apple-oauth.private_key', '-----BEGIN EC PRIVATE KEY-----abc-----END EC PRIVATE KEY-----' );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeFalse();
} );

it( 'normalizes empty-string config values to null', function (): void {
    config()->set( 'apple-oauth.client_id', '' );
    config()->set( 'apple-oauth.private_key', '' );

    /** @var ConfigDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBeNull();
    expect( $driver->getPrivateKey() )->toBeNull();
} );

it( 'throws when save is called on the read-only config driver', function (): void {
    $driver = app( ConfigurationRepository::class );

    expect( fn () => $driver->save( [ 'client_id' => 'x' ] ) )
        ->toThrow( RuntimeException::class );
} );
