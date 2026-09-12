<?php

declare( strict_types=1 );

use ArtisanPackUI\AppleOAuth\Scopes\ScopeRegistry;
use ArtisanPackUI\Hooks\Facades\Filter;

it( 'includes baseline name and email scopes by default', function (): void {
    $registry = app( ScopeRegistry::class );

    expect( $registry->all() )->toContain( 'name' );
    expect( $registry->all() )->toContain( 'email' );
} );

it( 'unions scopes contributed via the ap.apple-oauth.scopes filter', function (): void {
    Filter::add( 'ap.apple-oauth.scopes', function ( array $scopes ): array {
        $scopes[] = 'calendar.read';
        $scopes[] = 'calendar.write';
        return $scopes;
    } );

    $registry = app( ScopeRegistry::class );
    $all      = $registry->all();

    expect( $all )->toContain( 'calendar.read' );
    expect( $all )->toContain( 'calendar.write' );
} );

it( 'deduplicates scopes from multiple sources', function (): void {
    Filter::add( 'ap.apple-oauth.scopes', fn ( array $s ) => array_merge( $s, [ 'name', 'x' ] ) );

    $registry = app( ScopeRegistry::class );
    $registry->register( 'x' );

    $all = $registry->all();
    expect( array_count_values( $all )[ 'name' ] )->toBe( 1 );
    expect( array_count_values( $all )[ 'x' ] )->toBe( 1 );
} );

it( 'trims and ignores empty imperatively-registered scopes', function (): void {
    $registry = app( ScopeRegistry::class );
    $registry->register( '   ' );
    $registry->register( '  y  ' );

    expect( $registry->all() )->toContain( 'y' );
    expect( $registry->all() )->not->toContain( '' );
    expect( $registry->all() )->not->toContain( '   ' );
} );

it( 'computes missing scopes vs granted', function (): void {
    Filter::add( 'ap.apple-oauth.scopes', fn ( array $s ) => array_merge( $s, [ 'a', 'b', 'c' ] ) );

    $registry = app( ScopeRegistry::class );

    expect( $registry->missing( [ 'name', 'email', 'a' ] ) )->toContain( 'b' );
    expect( $registry->missing( [ 'name', 'email', 'a' ] ) )->toContain( 'c' );
    expect( $registry->missing( $registry->all() ) )->toBe( [] );
    expect( $registry->hasAllRequired( $registry->all() ) )->toBeTrue();
    expect( $registry->hasAllRequired( [] ) )->toBeFalse();
} );

it( 'ignores non-array filter returns gracefully', function (): void {
    Filter::add( 'ap.apple-oauth.scopes', fn () => 'not-an-array' );

    $registry = app( ScopeRegistry::class );

    expect( $registry->all() )->toContain( 'name' );
    expect( $registry->all() )->toContain( 'email' );
} );

it( 'is exposed via the AppleOAuth aggregator', function (): void {
    $apple = app( 'apple-oauth' );

    expect( $apple->scopes() )->toBeInstanceOf( ScopeRegistry::class );
} );

it( 'feeds the OAuthManager authorization URL scope union', function (): void {
    config()->set( 'apple-oauth.client_id', 'com.example.web' );
    config()->set( 'apple-oauth.redirect_uri', 'https://example.test/callback' );

    Filter::add( 'ap.apple-oauth.scopes', fn ( array $s ) => array_merge( $s, [ 'calendar.read' ] ) );

    $url = app( ArtisanPackUI\AppleOAuth\OAuth\OAuthManager::class )->authorizationUrl( 1 );

    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );

    expect( $query[ 'scope' ] )->toContain( 'name' );
    expect( $query[ 'scope' ] )->toContain( 'email' );
    expect( $query[ 'scope' ] )->toContain( 'calendar.read' );
} );
