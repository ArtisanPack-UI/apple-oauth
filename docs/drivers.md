---
title: Credential Drivers
---

# Credential Drivers

`artisanpack-ui/apple-oauth` stores **OAuth tokens** (`access_token`, `refresh_token`, `id_token`) in the `apple_connections` table, always. The choice of driver only affects **app credentials** — the `client_id`, `team_id`, `key_id`, `private_key`, `redirect_uri`, and optional static `client_secret` used to build the OAuth request and mint the client-secret JWT.

Three drivers ship in the box; pick the one that matches how your project stores secrets:

| Driver | Storage | Writable? | Best for |
|---|---|---|---|
| [`config`](Drivers/Config) (default) | `config/apple-oauth.php` / `.env` | No | Single-tenant apps where credentials belong in the deploy pipeline. |
| [`database`](Drivers/Database) | `apple_configurations` table (`private_key` + `client_secret` encrypted) | Yes | Multi-tenant apps, admin-UI-managed credentials, credential rotation without a deploy. |
| [`cms`](Drivers/CMS) | CMS framework Settings module (`private_key` + `client_secret` encrypted) | Yes | Projects already using `artisanpack-ui/cms-framework` — credentials live alongside every other site-level setting. |

## Selecting a driver

Set the driver via `.env`:

```env
APPLE_OAUTH_DRIVER=database
```

Or in `config/apple-oauth.php`:

```php
'driver' => 'database',
```

The service provider re-reads `config('apple-oauth.driver')` every time it resolves the [`ConfigurationRepository`](API-Reference/Configuration-Repository) binding, so runtime overrides work — useful for tests, multi-tenant middleware that swaps drivers per-tenant, etc.

## The contract

Every driver implements `ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository`:

```php
interface ConfigurationRepository
{
    public function getClientId(): ?string;
    public function getTeamId(): ?string;
    public function getKeyId(): ?string;
    public function getPrivateKey(): ?string;
    public function getRedirectUri(): ?string;
    public function getClientSecret(): ?string;
    public function save( array $credentials ): void;
    public function isConfigured(): bool;
}
```

Resolve it from the container:

```php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

$config = app( ConfigurationRepository::class );

if ( $config->isConfigured() ) {
    // safe to build authorization URLs
}
```

Full reference: [API Reference → Configuration Repository](API-Reference/Configuration-Repository).

## `isConfigured()` semantics

Every driver applies the same rule:

1. `client_id` and `redirect_uri` are always required.
2. **Plus** either a pre-minted `client_secret` OR the full signer inputs (`team_id`, `key_id`, `private_key`).

So `isConfigured()` returns `true` for either the "generated" mode (signer mints the JWT) or the "static override" mode (a pre-minted JWT is on file). See [Installation → Configuration](Installation/Configuration) for the two-mode split.

## Writing your own driver

Drivers are trivially replaceable — implement the eight-method contract and re-bind:

```php
// app/AppleOAuth/VaultDriver.php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

class VaultDriver implements ConfigurationRepository
{
    // ...
}

// AppServiceProvider::register()
$this->app->bind( ConfigurationRepository::class, VaultDriver::class );
```

Because `AppleOAuthServiceProvider::register()` uses `$this->app->bind()` (not `singleton()`) for the contract, your override wins as long as you register it after the package provider — which is the default order for app providers.

## Deeper topics

- [`config` driver](Drivers/Config) — env / config-file reader.
- [`database` driver](Drivers/Database) — encrypted `apple_configurations` row, atomic upsert.
- [`cms` driver](Drivers/CMS) — Settings-module integration and sanitize-time encryption.

---
Continue to [OAuth Flow](Oauth) →
