<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

require_once __DIR__ . '/Support/CmsSettingsStub.php';

pest()->extend( Tests\TestCase::class )
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in( 'Feature' );

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend( 'toBeOne', function () {
    return $this->toBe( 1 );
} );

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/**
 * An unsigned id_token as the broker relays it from Apple.
 */
function brokerIdToken( array $claims = [] ): string
{
    $claims = array_merge( [
        'iss'   => 'https://appleid.apple.com',
        'aud'   => 'com.workshop.broker',
        'exp'   => time() + 3600,
        'sub'   => '000123.broker.user',
        'email' => 'claim@privaterelay.appleid.com',
    ], $claims );

    $b64 = fn ( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );

    return $b64( '{"alg":"ES256"}' ) . '.' . $b64( (string) json_encode( $claims ) ) . '.signature';
}
