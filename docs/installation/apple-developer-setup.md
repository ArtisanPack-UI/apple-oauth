---
title: Apple Developer Setup
---

# Apple Developer Setup

Sign in with Apple requires a few Apple Developer artifacts before the OAuth flow can run. You need an active membership in the [Apple Developer Program](https://developer.apple.com/programs/) — Sign in with Apple is not available to free accounts.

You will finish this walkthrough with four values that go into your credential store: a **Services ID** (the OAuth `client_id`), a **Team ID**, a **Key ID**, and the contents of a `.p8` **private key** file.

## 1. Find your Team ID

Open [Apple Developer → Membership](https://developer.apple.com/account/#!/membership). Copy the 10-character **Team ID** shown near the top — this becomes `APPLE_OAUTH_TEAM_ID`.

## 2. Enable Sign in with Apple on an App ID

Sign in with Apple keys are always scoped to an App ID first, and the Services ID you'll create in the next step will be associated with the same App ID.

1. Open [Certificates, Identifiers & Profiles → Identifiers](https://developer.apple.com/account/resources/identifiers/list).
2. Either select an existing **App ID** (type: App IDs) or click **+** to register a new one.
3. In the App ID's capabilities list, tick **Sign in with Apple** and save.

## 3. Create the Services ID (this becomes your `client_id`)

1. Still in **Identifiers**, filter the list to **Services IDs** and click **+**.
2. Choose **Services IDs**, click **Continue**, and give it:
    - **Description**: a human-readable name (e.g. "Acme App — Web Sign in").
    - **Identifier**: a reverse-DNS identifier (e.g. `com.acme.app.web`). This string is your `APPLE_OAUTH_CLIENT_ID`.
3. Continue and register.
4. Open the newly-created Services ID and:
    - Tick **Sign in with Apple** to enable the capability.
    - Click **Configure** next to it.
    - Under **Primary App ID**, select the App ID from step 2.
    - Under **Domains and Subdomains**, add the public HTTPS domain that will host the redirect URI (e.g. `acme.example.com`). Apple verifies each domain by fetching `https://{domain}/.well-known/apple-developer-domain-association.txt`, so the hostname must be publicly reachable and TLS-terminated — `localhost` and unreachable `.test` hostnames will fail verification.
    - Under **Return URLs**, add the absolute HTTPS callback URL your app will handle, e.g. `https://acme.example.com/apple/callback`. This is your `APPLE_OAUTH_REDIRECT_URI`.
    - Save.

> Apple will not accept an `http://` return URL — the redirect must be HTTPS. For local development, expose your site through an HTTPS tunnel (ngrok, Expose, cloudflared) and register the tunnel's hostname on the Services ID, or register a real dev subdomain that resolves publicly and TLS-terminates. A `.test` hostname served only through your local resolver will not pass Apple's domain-association fetch.

## 4. Create the Sign in with Apple key (`.p8`)

1. Open [Keys](https://developer.apple.com/account/resources/authkeys/list) and click **+**.
2. Give the key a name, tick **Sign in with Apple**, and click **Configure** next to it.
3. Under **Primary App ID**, choose the App ID from step 2.
4. Continue → Register → Download.
5. Apple emits an **AuthKey_XXXXXXXXXX.p8** file. Copy the **Key ID** (the `XXXXXXXXXX` portion) — this is `APPLE_OAUTH_KEY_ID`.

> **Apple only lets you download the `.p8` once.** Store it somewhere backed up. If you lose it you must revoke the key and generate a new one.

Move the `.p8` file somewhere your application can read but is otherwise protected:

```bash
mkdir -p ~/.config/artisanpack
mv ~/Downloads/AuthKey_XXXXXXXXXX.p8 ~/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
chmod 600 ~/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
```

> The path above assumes a development machine where PHP runs as your user. In production, place the `.p8` at a path readable by the web-server user (e.g. `/etc/artisanpack/AuthKey_XXXXXXXXXX.p8` owned by `www-data` / `_www`, mode 600) — or use the [`database`](Drivers/Database) / [`cms`](Drivers/CMS) credential driver so the key material is stored encrypted in your app's data store instead.

## 5. Wire the four values into your app

Point the package at the values you just collected. The default [`config` driver](Drivers/Config) reads them from environment variables:

```env
APPLE_OAUTH_CLIENT_ID=com.acme.app.web
APPLE_OAUTH_TEAM_ID=ABCDE12345
APPLE_OAUTH_KEY_ID=XXXXXXXXXX
APPLE_OAUTH_PRIVATE_KEY=/Users/you/.config/artisanpack/AuthKey_XXXXXXXXXX.p8
APPLE_OAUTH_REDIRECT_URI=https://acme.example.com/apple/callback
```

`APPLE_OAUTH_PRIVATE_KEY` accepts either an absolute filesystem path to a `.p8` file (recommended) or an inline PEM string. Prefer the path form: `php artisan config:cache` freezes `env()` reads into `bootstrap/cache/config.php`, so an inline PEM would be persisted in cleartext inside the cache file. A path stores only the string; the key bytes stay wherever you point at.

## Rotating the `.p8`

Apple keys don't have a fixed expiry, but you should still rotate them periodically (typically annually) or immediately if the file leaks.

1. Generate a new key in Apple Developer → Keys → **+**, following step 4 above.
2. Wire the new `key_id` and `private_key` path into your app.
3. Discard the cached JWT so the next call mints a fresh one signed by the new key:

    ```php
    use ArtisanPackUI\AppleOAuth\Facades\AppleOAuth;

    AppleOAuth::clientSecret()->forget();
    ```

4. Revoke the old key in Apple Developer once you've confirmed the new one is minting valid JWTs.

Details: [Client-Secret JWT](Client-Secret).

## Deeper topics

- [Environment Variables](Installation/Environment-Variables) — every env var the package reads.
- [Configuration](Installation/Configuration) — full `config/apple-oauth.php` reference.
- [Client-Secret JWT](Client-Secret) — how the ES256 signer uses these credentials.
