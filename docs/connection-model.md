---
title: Connection Model
---

# Connection Model

`ArtisanPackUI\AppleOAuth\Models\AppleConnection` is the Eloquent model that represents a single connected Apple account for a user. Access, refresh, and id tokens are stored **encrypted** via Laravel's `encrypted` cast.

## Table: `apple_connections`

The migration lives at `database/migrations/2026_09_12_000000_create_apple_connections_table.php`.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | `bigInteger` | No | Primary key. |
| `user_id` | `unsignedBigInteger` | No | Unique. Points at the model named by `apple-oauth.user_model` (default `App\Models\User`). |
| `apple_user_id` | `string` | Yes | The id_token's `sub` claim — Apple's stable identifier for this user under this Services ID. |
| `email` | `string` | Yes | The id_token's `email` claim. Apple only releases this on the first authorization; preserved on updates. |
| `access_token` | `text` | Yes | Encrypted (`encrypted` cast). |
| `refresh_token` | `text` | Yes | Encrypted. Apple only issues on the initial authorization; preserved on updates. |
| `id_token` | `text` | Yes | Encrypted. The raw JWT from the last exchange. |
| `token_type` | `string` | No | Defaults to `'Bearer'`. |
| `scopes` | `text` | Yes | JSON-encoded array of granted scopes (`array` cast). |
| `expires_at` | `timestamp` | Yes | When the access token expires. `datetime` cast. |
| `status` | `string` | No | `'connected'` or `'disconnected'`. Defaults to `'connected'`. |
| `disconnect_reason` | `text` | Yes | Free-form reason set by `markDisconnected()`. |
| `created_at` / `updated_at` | `timestamp` | Yes | Standard Eloquent timestamps. |

Indexes:

- **Unique** on `user_id` — one Apple connection per user.
- **Composite** on `(user_id, status)` — for status-filtered lookups.

## Constants

```php
public const STATUS_CONNECTED    = 'connected';
public const STATUS_DISCONNECTED = 'disconnected';
```

Prefer these to string literals when comparing.

## Casts

```php
protected function casts(): array
{
    return [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'id_token'      => 'encrypted',
        'scopes'        => 'array',
        'expires_at'    => 'datetime',
    ];
}
```

The `encrypted` cast uses Laravel's `Crypt` facade — the ciphertext on disk is unreadable without `APP_KEY`. Rotating `APP_KEY` without a re-encryption pass will make every stored connection unreadable: reads throw `DecryptException`.

## Relationships

### `user(): BelongsTo`

```php
public function user(): BelongsTo
{
    /** @var class-string<Model> $userModel */
    $userModel = config( 'apple-oauth.user_model', 'App\\Models\\User' );

    return $this->belongsTo( $userModel, 'user_id' );
}
```

The related model is resolved from config at call time, so setting `APPLE_OAUTH_USER_MODEL` to a non-default class is a plain env change.

## Methods

### `isExpired(): bool`

Whether the stored access token is expired or expires within the next 60 seconds:

```php
public function isExpired(): bool
{
    if ( null === $this->expires_at ) {
        return true;
    }

    return $this->expires_at->copy()->subSeconds( 60 )->isPast();
}
```

Missing `expires_at` is treated as expired. The 60-second buffer means [`TokenManager`](Tokens) refreshes proactively — no API call ever ships with a token about to die.

### `isConnected(): bool`

```php
public function isConnected(): bool
{
    return self::STATUS_CONNECTED === $this->status;
}
```

Prefer this over `$connection->status === 'connected'` — the constant catches typos at compile time.

### `grantedScopes(): array`

The scopes granted to this connection, normalized to a `list<string>`:

```php
public function grantedScopes(): array
{
    $scopes = $this->scopes;

    if ( ! is_array( $scopes ) ) {
        return [];
    }

    return array_values( array_map( 'strval', $scopes ) );
}
```

Returns `[]` for a fresh connection where `scopes` hasn't been populated. See [Scopes](Scopes) for the registry that produces the union.

### `markDisconnected( ?string $reason = null ): void`

Flip the connection to disconnected and persist immediately:

```php
public function markDisconnected( ?string $reason = null ): void
{
    $this->status            = self::STATUS_DISCONNECTED;
    $this->disconnect_reason = $reason;
    $this->save();
}
```

Called by [`TokenManager::refresh()`](Tokens) automatically on `invalid_grant`. Call it manually when a user requests disconnection through your UI. See [OAuth → Disconnect](Oauth/Disconnect).

## `AppleUserProfile`

`ArtisanPackUI\AppleOAuth\OAuth\AppleUserProfile` is the immutable value object [`OAuthManager::handleCallback()`](Oauth/Callback) returns as part of the [`TokenResponse`](API-Reference/OAuth-Manager#tokenresponse). Only `sub` and `email` are copied into the `AppleConnection` row by [`TokenManager::store()`](Tokens) — the `apple_connections` schema has no name columns. **`firstName` and `lastName` are your application's to persist**: Apple releases them only on the very first authorization for a given Services ID, and never re-emits them. Copy them into your own `users` table (or wherever you keep display names) inside your callback handler, before the initial redirect back.

```php
final class AppleUserProfile
{
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
    ) {}

    public function hasName(): bool;
}
```

- `sub` — Apple's stable identifier for this user under this Services ID. Validated non-empty by `OAuthManager::validateIdTokenClaims()`.
- `email` — id_token `email` claim, if present.
- `firstName` / `lastName` — extracted from the one-shot `user` form field, only on the first authorization.
- `hasName()` — `true` when either `firstName` or `lastName` is set. Effectively "was this the first authorization?" — false on every subsequent callback.

## Model factories

The package doesn't ship a factory. Author your own in the consuming app:

```php
// database/factories/AppleConnectionFactory.php
namespace Database\Factories;

use App\Models\User;
use ArtisanPackUI\AppleOAuth\Models\AppleConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppleConnectionFactory extends Factory
{
    protected $model = AppleConnection::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'apple_user_id' => $this->faker->uuid(),
            'email'         => $this->faker->email(),
            'access_token'  => $this->faker->uuid(),
            'refresh_token' => $this->faker->uuid(),
            'id_token'      => 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.sig',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'status'        => AppleConnection::STATUS_CONNECTED,
        ];
    }

    public function disconnected(): static
    {
        return $this->state( fn () => [
            'status'            => AppleConnection::STATUS_DISCONNECTED,
            'disconnect_reason' => 'Test disconnect',
        ] );
    }
}
```

## Migration snapshot

For quick reference (from the package migration):

```php
Schema::create( 'apple_connections', function ( Blueprint $table ): void {
    $table->id();
    $table->unsignedBigInteger( 'user_id' );
    $table->string( 'apple_user_id' )->nullable();
    $table->string( 'email' )->nullable();
    $table->text( 'access_token' )->nullable();
    $table->text( 'refresh_token' )->nullable();
    $table->text( 'id_token' )->nullable();
    $table->string( 'token_type' )->default( 'Bearer' );
    $table->text( 'scopes' )->nullable();
    $table->timestamp( 'expires_at' )->nullable();
    $table->string( 'status' )->default( 'connected' );
    $table->text( 'disconnect_reason' )->nullable();
    $table->timestamps();

    $table->unique( 'user_id' );
    $table->index( [ 'user_id', 'status' ] );
} );
```
