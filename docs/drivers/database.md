---
title: Database Driver
---

# `database` Driver

Stores Apple OAuth app credentials in the `apple_configurations` table. The `private_key` and `client_secret` columns hold sensitive material and are stored encrypted using Laravel's `Encrypter` (`APP_KEY`). Non-sensitive columns (`client_id`, `team_id`, `key_id`, `redirect_uri`) are stored in cleartext.

## Enable

```env
APPLE_OAUTH_DRIVER=database
```

## Save credentials

```php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

app( ConfigurationRepository::class )->save( [
    'client_id'    => 'com.acme.app.web',
    'team_id'      => 'ABCDE12345',
    'key_id'       => 'XXXXXXXXXX',
    'private_key'  => file_get_contents( '/path/to/AuthKey_XXXXXXXXXX.p8' ),
    'redirect_uri' => 'https://app.acme.test/apple/callback',
] );
```

Omit any key to clear the corresponding stored value.

## Atomic single-row upsert

The `apple_configurations` table holds at most one row, constrained by a `singleton` sentinel column with a unique index. `save()` performs an atomic upsert anchored on that column:

- **SQLite / Postgres**: `INSERT ... ON CONFLICT ("singleton") DO UPDATE`
- **MySQL / MariaDB**: `INSERT ... ON DUPLICATE KEY UPDATE`

So two concurrent initial saves cannot both create a row — the second collides on the unique index and falls through to the UPDATE branch. `created_at` is preserved on subsequent saves; only `updated_at` and the credential columns are re-written.

## Encryption at rest

`private_key` and `client_secret` are encrypted with `$this->encrypter->encryptString( $value )` before being written. Non-sensitive columns are stored as plaintext.

Reads decrypt via `$this->encrypter->decryptString()`. If the ciphertext can no longer be decrypted (typically an `APP_KEY` rotation without a re-encryption pass), the driver:

1. Logs a warning: `artisanpack-ui/apple-oauth: failed to decrypt stored <column>; treating as unconfigured. Was APP_KEY rotated without re-encrypting the row?`
2. Returns `null` for that column — so the driver reports as unconfigured rather than throwing on every request.

This lets an operator recover by re-saving the credentials, without the entire integration going down while they investigate.

## `isConfigured()` behavior

Same rule as every driver: `client_id` + `redirect_uri` plus either a pre-minted `client_secret` or the full `team_id` / `key_id` / `private_key` triple. A row whose encrypted columns failed to decrypt is treated as unconfigured for those columns.

## Multi-tenant credentials

For per-tenant credentials, rebind the driver so it reads/writes on a tenant-scoped database connection:

```php
use ArtisanPackUI\AppleOAuth\Configuration\DatabaseDriver;
use Illuminate\Contracts\Foundation\Application;

$this->app->singleton( DatabaseDriver::class, function ( Application $app ): DatabaseDriver {
    return new DatabaseDriver(
        $app[ 'db' ]->connection( 'tenant' ),
        $app[ 'encrypter' ],
    );
} );
```

Every tenant then gets its own `apple_configurations` row on its own database connection.

## When to use it

- Multi-tenant apps where each tenant has their own Services ID.
- Admin-UI-managed credentials — an operator uploads the `.p8` and enters IDs through your app rather than editing `.env`.
- Credential rotation without a redeploy.

## When not to use it

- Single-tenant apps where credentials belong in the deploy pipeline — the [`config` driver](Drivers/Config) is simpler.
- Projects already using `artisanpack-ui/cms-framework` — the [`cms` driver](Drivers/CMS) surfaces the credentials alongside every other site setting.

## Per-request cache

The driver caches the loaded row in a `?array $cache` field. Subsequent getter calls in the same request skip the query. `save()` clears the cache, so the next read pulls fresh values.

## Testing

Point the driver at an in-memory SQLite database via Orchestra Testbench, run the migrations, and drive it directly:

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
