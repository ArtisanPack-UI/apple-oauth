<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Configuration\DatabaseDriver;
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'apple-oauth.driver', 'database' );
} );

it( 'uses the database driver when configured', function (): void {
    expect( app( ConfigurationRepository::class ) )->toBeInstanceOf( DatabaseDriver::class );
} );

it( 'persists and reads every credential, encrypting the sensitive columns', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'com.example.service',
        'team_id'       => 'TEAM12345',
        'key_id'        => 'KEY67890',
        'private_key'   => "-----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----",
        'redirect_uri'  => 'https://example.test/callback',
        'client_secret' => 'preminted.jwt.here',
    ] );

    expect( $driver->getClientId() )->toBe( 'com.example.service' );
    expect( $driver->getTeamId() )->toBe( 'TEAM12345' );
    expect( $driver->getKeyId() )->toBe( 'KEY67890' );
    expect( $driver->getPrivateKey() )->toBe( "-----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----" );
    expect( $driver->getRedirectUri() )->toBe( 'https://example.test/callback' );
    expect( $driver->getClientSecret() )->toBe( 'preminted.jwt.here' );

    $stored = DB::table( 'apple_configurations' )->first();
    expect( $stored->private_key )->not->toBe( "-----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----" );
    expect( $stored->client_secret )->not->toBe( 'preminted.jwt.here' );
    expect( $stored->client_id )->toBe( 'com.example.service' );
} );

it( 'rejects a duplicate singleton row so a concurrent initial save cannot create a second one', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'    => 'com.example.first',
        'redirect_uri' => 'https://example.test/callback',
        'team_id'      => 'T1',
        'key_id'       => 'K1',
        'private_key'  => 'pk-1',
    ] );

    // Simulate a second writer that raced past the initial existence check
    // in a naive implementation and tried to insert its own credential row.
    // The unique index on `singleton` must reject it so `first()` can never
    // return stale credentials from a duplicate row.
    expect( fn () => DB::table( 'apple_configurations' )->insert( [
        'singleton'    => 'default',
        'client_id'    => 'com.example.second',
        'redirect_uri' => 'https://example.test/callback',
        'created_at'   => now(),
        'updated_at'   => now(),
    ] ) )->toThrow( QueryException::class );

    expect( DB::table( 'apple_configurations' )->count() )->toBe( 1 );
} );

it( 'preserves created_at across subsequent saves and only bumps updated_at', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'    => 'com.example.service',
        'redirect_uri' => 'https://example.test/callback',
        'team_id'      => 'T1',
        'key_id'       => 'K1',
        'private_key'  => 'pk-1',
    ] );

    $initial = DB::table( 'apple_configurations' )->first();

    // Ensure any DATETIME-resolution timer would tick between the two writes.
    sleep( 1 );

    app()->forgetInstance( DatabaseDriver::class );
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'    => 'com.example.service',
        'redirect_uri' => 'https://example.test/callback',
        'team_id'      => 'T2',
        'key_id'       => 'K2',
        'private_key'  => 'pk-2',
    ] );

    $updated = DB::table( 'apple_configurations' )->first();

    expect( $updated->created_at )->toBe( $initial->created_at );
    expect( $updated->updated_at )->not->toBe( $initial->updated_at );
} );

it( 'updates the existing row on subsequent saves', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'    => 'app-1',
        'team_id'      => 'T1',
        'key_id'       => 'K1',
        'private_key'  => 'pk-1',
        'redirect_uri' => 'https://a.test/cb',
    ] );

    app()->forgetInstance( DatabaseDriver::class );
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'    => 'app-2',
        'team_id'      => 'T2',
        'key_id'       => 'K2',
        'private_key'  => 'pk-2',
        'redirect_uri' => 'https://b.test/cb',
    ] );

    expect( DB::table( 'apple_configurations' )->count() )->toBe( 1 );
    expect( $driver->getClientId() )->toBe( 'app-2' );
    expect( $driver->getPrivateKey() )->toBe( 'pk-2' );
} );

it( 'reports configured with only a pre-minted client_secret plus client_id and redirect_uri', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'com.example.service',
        'redirect_uri'  => 'https://example.test/callback',
        'client_secret' => 'preminted.jwt.here',
    ] );

    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'reports configured with the full signer inputs and no client_secret', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'    => 'com.example.service',
        'team_id'      => 'TEAM12345',
        'key_id'       => 'KEY67890',
        'private_key'  => 'pk',
        'redirect_uri' => 'https://example.test/callback',
    ] );

    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'reports unconfigured when the required base credentials are missing', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'redirect_uri'  => 'https://example.test/callback',
        'client_secret' => 'preminted.jwt.here',
    ] );

    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'clears a stored sensitive value when null is passed on save', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'com.example.service',
        'redirect_uri'  => 'https://example.test/callback',
        'client_secret' => 'preminted.jwt.here',
    ] );

    expect( $driver->getClientSecret() )->toBe( 'preminted.jwt.here' );

    $driver->save( [
        'client_id'     => 'com.example.service',
        'redirect_uri'  => 'https://example.test/callback',
        'client_secret' => null,
    ] );

    expect( $driver->getClientSecret() )->toBeNull();
} );

it( 'returns null and logs a warning when a stored ciphertext cannot be decrypted', function (): void {
    Log::spy();

    DB::table( 'apple_configurations' )->insert( [
        'client_id'    => 'com.example.service',
        'redirect_uri' => 'https://example.test/callback',
        'private_key'  => 'not-a-valid-ciphertext',
        'created_at'   => now(),
        'updated_at'   => now(),
    ] );

    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getPrivateKey() )->toBeNull();

    Log::shouldHaveReceived( 'warning' )->once();
} );
