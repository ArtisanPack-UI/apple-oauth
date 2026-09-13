---
title: Testing
---

# Testing

The package ships with a Pest test suite you can use as a reference — see `tests/Unit/` and `tests/Feature/` in the package. This page collects patterns for testing your own code against `artisanpack-ui/apple-oauth`.

## Test bootstrap

The package's own tests use Orchestra Testbench with a base `TestCase` in `tests/TestCase.php`. To wire up an app-level test that talks to the package:

```php
use ArtisanPackUI\AppleOAuth\AppleOAuthServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders( $app ): array
    {
        return [ AppleOAuthServiceProvider::class ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/../vendor/artisanpack-ui/apple-oauth/database/migrations',
        );
    }
}
```

Or use the `RefreshDatabase` trait in a normal Laravel `TestCase` — the package registers its migrations via `$this->loadMigrationsFrom()` in `boot()`, so `php artisan migrate` in the test DB is enough.

## Faking the `TokenProvider`

The cheapest way to test downstream code is to rebind [`TokenProvider`](API-Reference/Token-Provider) to a hand-rolled fake:

```php
use ArtisanPackUI\AppleOAuth\Contracts\TokenProvider;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

$this->app->instance( TokenProvider::class, new class implements TokenProvider {
    public function accessTokenFor( AppleConnection $connection ): string
    {
        return 'test-access-token';
    }
} );
```

Zero HTTP fakes, zero credential setup, zero session gymnastics — the fake never touches Apple. Every downstream package should use this pattern for its own tests.

For failure paths:

```php
use ArtisanPackUI\AppleOAuth\Exceptions\TokenRefreshException;

$this->app->instance( TokenProvider::class, new class implements TokenProvider {
    public function accessTokenFor( AppleConnection $connection ): string
    {
        throw new TokenRefreshException( 'Apple connection is disconnected.' );
    }
} );
```

## Testing the OAuth flow directly

When you do need to exercise the flow itself, fake Apple's endpoints with `Http::fake()`:

```php
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://appleid.apple.com/auth/token' => Http::response( [
        'access_token'  => 'initial-access-token',
        'refresh_token' => 'a-refresh-token',
        'id_token'      => makeIdToken( sub: 'apple-user-abc', email: 'you@example.com', nonce: session( 'apple-oauth.nonce' ), aud: config( 'apple-oauth.client_id' ) ),
        'expires_in'    => 3600,
        'token_type'    => 'Bearer',
    ] ),
] );
```

`makeIdToken()` builds a minimal id_token payload — the package doesn't verify signatures, so the signature segment can be anything:

```php
function makeIdToken( string $sub, string $email, string $nonce, string $aud ): string
{
    $header = base64url( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
    $claims = base64url( json_encode( [
        'iss'   => 'https://appleid.apple.com',
        'aud'   => $aud,
        'exp'   => time() + 3600,
        'nonce' => $nonce,
        'sub'   => $sub,
        'email' => $email,
    ] ) );

    return "{$header}.{$claims}.signature";
}

function base64url( string $raw ): string
{
    return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}
```

Then drive the flow:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;

$user = User::factory()->create();

// Pretend the /apple/connect handler ran — build the URL so the session is primed.
AppleOAuth::oauth()->authorizationUrl( $user->id );

// Grab the state + nonce for the fake callback.
$state = session( 'apple-oauth.state' );

$response = AppleOAuth::oauth()->handleCallback(
    code:          'test-auth-code',
    returnedState: $state,
    userPayload:   json_encode( [ 'name' => [ 'firstName' => 'Ada', 'lastName' => 'Lovelace' ] ] ),
);

$connection = AppleOAuth::tokens()->store( $response );

expect( $connection->email )->toBe( 'you@example.com' );
expect( $connection->apple_user_id )->toBe( 'apple-user-abc' );
expect( $connection->access_token )->toBe( 'initial-access-token' );
```

## Testing the token manager

Same pattern — fake the token endpoint and assert the manager returns the fresh token:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

Http::fake( [
    'https://appleid.apple.com/auth/token' => Http::response( [
        'access_token' => 'refreshed',
        'expires_in'   => 3600,
        'token_type'   => 'Bearer',
    ] ),
] );

$connection = AppleConnection::factory()->create( [
    'access_token'  => 'expired-token',
    'refresh_token' => 'a-refresh-token',
    'expires_at'    => now()->subMinute(),
    'status'        => 'connected',
] );

expect( AppleOAuth::tokens()->getValidAccessToken( $connection ) )->toBe( 'refreshed' );
expect( $connection->fresh()->access_token )->toBe( 'refreshed' );
```

Test the `invalid_grant` auto-disconnect:

```php
Http::fake( [
    'https://appleid.apple.com/auth/token' => Http::response( [
        'error' => 'invalid_grant',
    ], 400 ),
] );

expect( fn () => AppleOAuth::tokens()->refresh( $connection ) )
    ->toThrow( TokenRefreshException::class );

expect( $connection->fresh()->status )->toBe( 'disconnected' );
expect( $connection->fresh()->disconnect_reason )
    ->toBe( 'Refresh token revoked or expired.' );
```

## Testing the client-secret signer

For unit-testing code that touches [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator), point `apple-oauth.client_secret` at a static string in your test's `setUp()` — the manager will use it verbatim and skip the ES256 signer entirely:

```php
config( [
    'apple-oauth.client_id'     => 'com.tests.app.web',
    'apple-oauth.redirect_uri'  => 'https://tests.test/apple/callback',
    'apple-oauth.client_secret' => 'stub-client-secret-jwt',
] );
```

If you want to actually exercise the signer, generate a fixture P-256 key once and commit it under `tests/Fixtures/`:

```bash
openssl ecparam -name prime256v1 -genkey -noout -out tests/Fixtures/test-key.p8
```

Then wire the path into config:

```php
config( [
    'apple-oauth.team_id'     => 'TESTTEAM01',
    'apple-oauth.key_id'      => 'TESTKEY001',
    'apple-oauth.private_key' => __DIR__ . '/Fixtures/test-key.p8',
] );

$jwt = AppleOAuth::clientSecret()->generate();
expect( $jwt )->toBeString()->toContain( '.' );
```

## Testing scope contributions

Register a scope inside the test and assert `all()` picks it up:

```php
use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.apple-oauth.scopes', function ( array $scopes ): array {
    $scopes[] = 'my.custom.scope';
    return $scopes;
} );

expect( AppleOAuth::scopes()->all() )->toContain( 'my.custom.scope' );
```

Filter registrations persist across tests within the same process — call `Filter::remove()` in `tearDown()` or use a fresh filter registry per test if you need isolation.

## Testing configuration drivers

Switch drivers in the test's `setUp()`:

```php
config( [ 'apple-oauth.driver' => 'database' ] );

app( ConfigurationRepository::class )->save( [
    'client_id'    => 'com.tests.app.web',
    'team_id'      => 'TESTTEAM01',
    'key_id'       => 'TESTKEY001',
    'private_key'  => $fixturePem,
    'redirect_uri' => 'https://tests.test/apple/callback',
] );

expect( app( ConfigurationRepository::class )->isConfigured() )->toBeTrue();
```

Because `ConfigurationRepository` is `bind()`ed (not `singleton()`), the container re-reads `config('apple-oauth.driver')` on each resolve — you can flip the driver mid-test without container flushing.

## Session helpers

`OAuthManager::authorizationUrl()` stashes `apple-oauth.state`, `apple-oauth.nonce`, `apple-oauth.user_id`. If you want to drive `handleCallback()` without calling `authorizationUrl()` first, prime the session yourself:

```php
session( [
    'apple-oauth.state'   => 'test-state',
    'apple-oauth.nonce'   => 'test-nonce',
    'apple-oauth.user_id' => $user->id,
] );

// Then handleCallback('...', 'test-state', ...) works.
```

## Testing your own callback route

```php
use Illuminate\Support\Facades\Http;

Http::fake( [ 'https://appleid.apple.com/auth/token' => Http::response( [ /* ... */ ] ) ] );

session( [
    'apple-oauth.state'   => 'test-state',
    'apple-oauth.nonce'   => 'test-nonce',
    'apple-oauth.user_id' => $user->id,
] );

$this->actingAs( $user )
    ->post( '/apple/callback', [
        'code'  => 'test-code',
        'state' => 'test-state',
        'user'  => '{"name":{"firstName":"Ada","lastName":"Lovelace"}}',
    ] )
    ->assertRedirect( '/account' );

$this->assertDatabaseHas( 'apple_connections', [
    'user_id' => $user->id,
    'status'  => 'connected',
] );
```

## Running the package's own tests

From the package directory:

```bash
composer test          # runs Pest
composer lint          # php-cs-fixer --dry-run + phpcs
composer fix           # php-cs-fixer fix
```

From this dev app (with the package symlinked):

```bash
php artisan test --compact
```
