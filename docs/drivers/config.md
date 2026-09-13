---
title: Config Driver
---

# `config` Driver

The default driver. Reads credentials from Laravel's config repository, which in turn reads from `.env`.

## Configuration

```env
APPLE_OAUTH_DRIVER=config    # optional, this is the default
APPLE_OAUTH_CLIENT_ID=com.acme.app.web
APPLE_OAUTH_TEAM_ID=ABCDE12345
APPLE_OAUTH_KEY_ID=XXXXXXXXXX
APPLE_OAUTH_PRIVATE_KEY=/Users/you/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
APPLE_OAUTH_REDIRECT_URI=https://app.acme.test/apple/callback
```

The keys map directly to `config/apple-oauth.php`:

```php
'client_id'     => env( 'APPLE_OAUTH_CLIENT_ID' ),
'team_id'       => env( 'APPLE_OAUTH_TEAM_ID' ),
'key_id'        => env( 'APPLE_OAUTH_KEY_ID' ),
'private_key'   => env( 'APPLE_OAUTH_PRIVATE_KEY' ),
'client_secret' => env( 'APPLE_OAUTH_CLIENT_SECRET' ),
'redirect_uri'  => env( 'APPLE_OAUTH_REDIRECT_URI' ),
```

You can also set these values directly in the config file if you'd rather commit them (obviously don't commit the private key or client secret).

## Private key: path vs. inline PEM

`APPLE_OAUTH_PRIVATE_KEY` accepts either shape:

- **Absolute filesystem path** to a `.p8` file (recommended).
- **Inline PEM string** — the full `-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----` block.

Prefer the path form. `php artisan config:cache` freezes `env()` reads into `bootstrap/cache/config.php`, so an inline PEM would be persisted in cleartext inside the cache file. A path stores only the string; the key bytes stay wherever you point at.

[`ClientSecretGenerator`](API-Reference/Client-Secret-Generator) auto-detects the shape by checking whether the trimmed string starts with `-----BEGIN`.

## Read-only

The `config` driver is read-only. Calling `save()` throws a `RuntimeException`:

```
The config driver is read-only. Switch to the database driver to persist credentials.
```

This is deliberate — the config driver's whole point is that credentials live in your deploy pipeline. Writing back to the file wouldn't be persisted across container restarts, and mutating environment values at runtime tends to leak between requests.

Need to update credentials programmatically? Switch to the [`database`](Drivers/Database) or [`cms`](Drivers/CMS) driver.

## When to use it

- Single-tenant apps.
- Credentials rotate rarely enough that a redeploy is fine.
- You already manage secrets through a deploy pipeline (Envoyer, Vapor, GitLab CI variables, Kubernetes secrets, …).

## When not to use it

- Multi-tenant apps where each tenant has their own Services ID.
- Admin-UI-managed credentials.
- Anywhere users need to see or edit the credentials without a deploy.

## `isConfigured()` behavior

`client_id` and `redirect_uri` are always required. Beyond that, either a pre-minted `client_secret` OR the full signer inputs (`team_id`, `key_id`, `private_key`) must be present:

```php
public function isConfigured(): bool
{
    if ( empty( $this->getClientId() ) || empty( $this->getRedirectUri() ) ) {
        return false;
    }

    if ( ! empty( $this->getClientSecret() ) ) {
        return true;
    }

    return ! empty( $this->getTeamId() )
        && ! empty( $this->getKeyId() )
        && ! empty( $this->getPrivateKey() );
}
```

Useful as a "did I forget to sync .env?" tripwire — the check flips to `false` the moment a container boots without the vars set.

## Testing

Point the config repository at fixture values in your test's `setUp()`:

```php
config( [
    'apple-oauth.driver'        => 'config',
    'apple-oauth.client_id'     => 'com.tests.app.web',
    'apple-oauth.team_id'       => 'TESTTEAM01',
    'apple-oauth.key_id'        => 'TESTKEY001',
    'apple-oauth.private_key'   => $fixturePem,
    'apple-oauth.redirect_uri'  => 'https://tests.test/apple/callback',
] );
```

Since Orchestra Testbench doesn't read your app's `.env`, the config driver is often the easiest to test against.
