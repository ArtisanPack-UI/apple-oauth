---
title: CMS Driver
---

# `cms` Driver

Delegates credential get / set to the [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) Settings module, so Apple OAuth credentials live alongside every other site-level setting the CMS manages. Only available when the framework is installed — the driver fails fast at construction time otherwise.

## Enable

```env
APPLE_OAUTH_DRIVER=cms
```

## Save credentials

```php
use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

app( ConfigurationRepository::class )->save( [
    'client_id'    => 'com.acme.app.web',
    'team_id'      => 'ABCDE12345',
    'key_id'       => 'XXXXXXXXXX',
    'private_key'  => file_get_contents( '/path/to/AuthKey_XXXXXXXXXX.p8' ),
    'redirect_uri' => 'https://acme.example.com/apple/callback',
] );
```

Reads and writes go through `apGetSetting()` / `apUpdateSetting()`, so any Settings API a project already exposes (admin UI, tinker command, config panel) can manage these credentials.

## Setting keys

The driver stores each credential under a stable setting key:

| Constant | Setting key |
|---|---|
| `CmsSettingsDriver::KEY_CLIENT_ID` | `artisanpack_apple_oauth_client_id` |
| `CmsSettingsDriver::KEY_TEAM_ID` | `artisanpack_apple_oauth_team_id` |
| `CmsSettingsDriver::KEY_KEY_ID` | `artisanpack_apple_oauth_key_id` |
| `CmsSettingsDriver::KEY_PRIVATE_KEY` | `artisanpack_apple_oauth_private_key` |
| `CmsSettingsDriver::KEY_REDIRECT_URI` | `artisanpack_apple_oauth_redirect_uri` |
| `CmsSettingsDriver::KEY_CLIENT_SECRET` | `artisanpack_apple_oauth_client_secret` |

The service provider registers these with the CMS framework at boot time (matching `apRegisterSetting()` calls) so they surface in the framework's settings UI.

## Encryption at rest

`private_key` and `client_secret` are encrypted at rest — the service provider registers sanitize callbacks with the CMS framework so both this driver's writes and any save through the Settings UI persist the same encrypted ciphertext. The driver's own `save()` passes plaintext through verbatim; the sanitize callbacks own the encryption.

Reads decrypt via `$this->encrypter->decryptString()`. If the ciphertext can no longer be decrypted (typically an `APP_KEY` rotation without a re-encryption pass), the driver:

1. Logs a warning: `artisanpack-ui/apple-oauth: failed to decrypt CMS-stored <key>; treating as unconfigured. Was APP_KEY rotated without re-encrypting the setting?`
2. Returns `null` for that key — so the driver reports as unconfigured rather than throwing on every request.

## Fail-fast when the framework isn't installed

The driver's constructor requires that `apGetSetting()` and `apUpdateSetting()` both exist as global functions. If they don't (i.e., you set `APPLE_OAUTH_DRIVER=cms` without installing `artisanpack-ui/cms-framework`), it throws a `RuntimeException` at construction time:

```
artisanpack-ui/apple-oauth: the "cms" credential driver requires artisanpack-ui/cms-framework to be installed (missing helper: apGetSetting()). Install the framework or switch APPLE_OAUTH_DRIVER to "config" or "database".
```

The failure surfaces on the first container resolution — usually the first request that touches OAuth — with a message that names the concrete fix, rather than a bare undefined-function error later in the flow.

## `isConfigured()` behavior

Same rule as every driver: `client_id` + `redirect_uri` plus either a pre-minted `client_secret` or the full `team_id` / `key_id` / `private_key` triple. A row whose encrypted keys failed to decrypt is treated as unconfigured for those keys.

## Per-request cache

The driver caches the loaded settings in a `?array $cache` field. Subsequent getter calls in the same request skip the settings queries. `save()` clears the cache; the `flush()` method exists for tests that want to force a re-read without a `save()`.

## When to use it

- Projects already using `artisanpack-ui/cms-framework`.
- CMS-managed configuration where credentials belong in the settings module alongside site name, contact email, etc.

## When not to use it

- Projects without the CMS framework installed.
- Apps that want a plain, portable credential store — use the [`database` driver](Drivers/Database) instead.
