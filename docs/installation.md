---
title: Installation
---

# Installation

## Install via Composer

```bash
composer require artisanpack-ui/apple-oauth
```

The package auto-registers via Laravel's package discovery:

- **Service provider**: `ArtisanPackUI\AppleOAuth\AppleOAuthServiceProvider`
- **Facade alias**: `AppleOAuth` (`ArtisanPackUI\AppleOAuth\Facades\AppleOAuth`)

No manual changes to `config/app.php` are required in a standard Laravel app.

## Run migrations

The service provider loads its migrations automatically via `$this->loadMigrationsFrom()`, so a plain `php artisan migrate` picks them up:

```bash
php artisan migrate
```

This creates two tables:

- `apple_connections` — the per-user connection row (encrypted access + refresh + id tokens, `apple_user_id`, email, granted scopes, expiry, status). Used by every driver — tokens always live in the database.
- `apple_configurations` — used only by the [`database` credential driver](Drivers/Database) to store OAuth client credentials. Unused by the `config` and `cms` drivers.

See [Connection Model](Connection-Model) for a full column reference.

Publish the migrations only if you need to edit them:

```bash
php artisan vendor:publish --tag=apple-oauth-migrations
```

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=apple-oauth-config
```

Publishes `config/apple-oauth.php`. Override the credential driver, endpoints, client-secret JWT lifetime, or the user model here. Full reference: [Configuration](Installation/Configuration).

## Apple Developer setup

Before a user can connect, you need four values from Apple Developer: a **Services ID** (the OAuth `client_id`), a **Team ID**, a **Key ID**, and a `.p8` **private key** file.

The full walkthrough lives on its own page: [Apple Developer Setup](Installation/Apple-Developer-Setup).

## Store your credentials

Choose a driver by setting `APPLE_OAUTH_DRIVER` (default `config`):

```env
APPLE_OAUTH_DRIVER=config
APPLE_OAUTH_CLIENT_ID=com.acme.app.web
APPLE_OAUTH_TEAM_ID=ABCDE12345
APPLE_OAUTH_KEY_ID=XXXXXXXXXX
APPLE_OAUTH_PRIVATE_KEY=/Users/you/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
APPLE_OAUTH_REDIRECT_URI=https://acme.example.com/apple/callback
```

Or switch to a writable driver — see [Credential Drivers](Drivers).

## Mount your OAuth routes

The package does **not** ship web routes. Apple gates the one-shot `user` payload on `response_mode=form_post`, which means the callback is a `POST` — your app owns it so you can attach auth middleware, layouts, and post-connect redirects however you like. See [Getting Started → Mount a connect route](Getting-Started#5-mount-a-connect-route) and [OAuth → Callback](Oauth/Callback) for the wiring.

## Verify the install

```bash
php artisan tinker
```

```php
app( 'apple-oauth' )->oauth()->authorizationUrl( 1 );
// URL string starting with https://appleid.apple.com/auth/authorize?...
```

A returned URL means every binding is wired and the credential driver reports `isConfigured() === true`. If credentials are missing, `authorizationUrl()` throws `OAuthException("Apple OAuth credentials are not configured.")` — see [`isConfigured()`](API-Reference/Configuration-Repository#isconfigured) for exactly what each driver considers "configured".

## Deeper topics

- [Requirements](Installation/Requirements) — PHP, Laravel, and peer-package versions in full detail.
- [Apple Developer Setup](Installation/Apple-Developer-Setup) — Team ID, App ID, Services ID, `.p8` key, return URLs, env wiring.
- [Environment Variables](Installation/Environment-Variables) — every env var the package reads.
- [Configuration](Installation/Configuration) — full `config/apple-oauth.php` reference.

---
Continue to [Credential Drivers](Drivers) →
