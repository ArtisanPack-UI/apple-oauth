---
title: ConfigurationRepository
---

# `ConfigurationRepository`

`ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository` is the contract every credential driver implements. Prose walkthrough: [Credential Drivers](Drivers).

OAuth tokens are **not** stored here — see [`AppleConnection`](API-Reference/Connection-Model) for per-user access, refresh, and id tokens. The credentials modelled here are the ones needed to bootstrap the flow: the Services ID (`client_id`), the Apple Developer `team_id`, the `key_id` of the signing key, the `private_key` contents or path, the OAuth `redirect_uri`, and an optional pre-minted static `client_secret` JWT for local testing.

## Interface

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

## Getters

Each returns the stored value or `null` when unset / cleared / (for encrypted drivers) undecryptable.

| Method | Value shape | Notes |
|---|---|---|
| `getClientId()` | The Services ID from Apple Developer. | e.g. `com.acme.app.web`. |
| `getTeamId()` | 10-character Apple Developer team ID. | Not required when a pre-minted `client_secret` is on file. |
| `getKeyId()` | 10-character Key ID. | Not required when a pre-minted `client_secret` is on file. |
| `getPrivateKey()` | Absolute path to a `.p8` file, or inline PEM. | Not required when a pre-minted `client_secret` is on file. [`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) detects the shape by whether the trimmed value starts with `-----BEGIN`. |
| `getRedirectUri()` | Absolute HTTPS URL. | Must match the Services ID's Return URLs list. |
| `getClientSecret()` | Pre-minted JWT string. | Bypasses the ES256 signer entirely when set. Intended for local testing. |

## `save( array $credentials ): void`

Persist a full credential set. Accepted keys: `client_id`, `team_id`, `key_id`, `private_key`, `redirect_uri`, `client_secret`. Any omitted key clears the corresponding stored value.

The [`config` driver](Drivers/Config) is read-only and throws `RuntimeException` here.

The [`database`](Drivers/Database) and [`cms`](Drivers/CMS) drivers persist a single credential row. Sensitive fields (`private_key`, `client_secret`) are encrypted before storage — the `database` driver encrypts inline via `$this->encrypter->encryptString()`; the `cms` driver passes plaintext through and lets sanitize callbacks (registered by the service provider) handle encryption at the Settings layer.

## `isConfigured(): bool`

Whether the repository has a usable credential set. Every driver applies the same rule:

1. `client_id` and `redirect_uri` are always required.
2. **Plus** either a pre-minted `client_secret` OR the full signer triple: `team_id`, `key_id`, `private_key`.

Return `false` if `client_id` or `redirect_uri` are empty. Return `true` if a `client_secret` is on file. Otherwise, return `true` only when `team_id`, `key_id`, and `private_key` are all non-empty.

## Resolving the current driver

```php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

$driver = app( ConfigurationRepository::class );

if ( $driver->isConfigured() ) {
    // safe to build authorization URLs
}
```

The service provider `bind()`s the contract (not `singleton()`), so the container re-reads `config('apple-oauth.driver')` on every resolve — you can flip the driver mid-request or mid-test without container flushing.

## Writing your own driver

Implement the eight-method contract and re-bind:

```php
namespace App\AppleOAuth;

use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

class VaultDriver implements ConfigurationRepository
{
    public function __construct( protected VaultClient $vault ) {}

    public function getClientId(): ?string    { return $this->vault->read( 'apple/client_id' ); }
    public function getTeamId(): ?string      { return $this->vault->read( 'apple/team_id' ); }
    // ... etc
    public function save( array $credentials ): void { /* ... */ }
    public function isConfigured(): bool { /* ... */ }
}
```

```php
// AppServiceProvider::register()
$this->app->bind( ConfigurationRepository::class, VaultDriver::class );
```

Ships ahead of the package provider's binding thanks to Laravel's normal provider ordering (app providers register last).

## Related drivers

- [`ConfigDriver`](Drivers/Config) — reads from `config/apple-oauth.php` / `.env`. Read-only.
- [`DatabaseDriver`](Drivers/Database) — encrypted `apple_configurations` row, atomic upsert.
- [`CmsSettingsDriver`](Drivers/CMS) — Settings-module integration, fail-fast when the CMS framework isn't installed.
