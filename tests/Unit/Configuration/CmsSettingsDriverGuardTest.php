<?php

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Configuration;

/**
 * Namespace-local override for `function_exists()`. PHP resolves an
 * unqualified call to `function_exists()` inside the
 * `ArtisanPackUI\AppleOAuth\Configuration` namespace to this function
 * first — so the CmsSettingsDriver constructor's `function_exists()`
 * probes land here.
 *
 * The shim is opt-in: it only hides the CMS helpers while the
 * `__ap_apple_hide_cms_helpers` global flag is truthy, so other tests
 * (which rely on the real stubs declared in tests/Support/CmsSettingsStub.php)
 * see normal resolution.
 *
 * @since 1.0.0
 */
function function_exists( string $name ): bool
{
    if (
        ! empty( $GLOBALS[ '__ap_apple_hide_cms_helpers' ] )
        && in_array( $name, [ 'apGetSetting', 'apUpdateSetting' ], true )
    ) {
        return false;
    }

    return \function_exists( $name );
}

namespace Tests\Unit\Configuration;

use ArtisanPackUI\AppleOAuth\Configuration\CmsSettingsDriver;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

beforeEach( function (): void {
    $GLOBALS[ '__ap_apple_hide_cms_helpers' ] = true;
} );

afterEach( function (): void {
    $GLOBALS[ '__ap_apple_hide_cms_helpers' ] = false;
} );

it( 'throws a clear RuntimeException when the cms-framework helpers are absent', function (): void {
    $encrypter = new Encrypter( random_bytes( 32 ), 'AES-256-CBC' );

    expect( fn () => new CmsSettingsDriver( $encrypter ) )
        ->toThrow(
            RuntimeException::class,
            'artisanpack-ui/cms-framework to be installed',
        );
} );
