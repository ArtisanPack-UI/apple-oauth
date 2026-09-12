<?php

declare( strict_types=1 );

it( 'merges the package configuration under the apple-oauth key', function (): void {
    expect( config( 'apple-oauth' ) )->toBeArray();
} );

it( 'publishes the config file under the apple-oauth-config tag', function (): void {
    $paths = Illuminate\Support\ServiceProvider::pathsToPublish(
        ArtisanPackUI\AppleOAuth\AppleOAuthServiceProvider::class,
        'apple-oauth-config',
    );

    expect( $paths )
        ->toBeArray()
        ->not->toBeEmpty();
} );
