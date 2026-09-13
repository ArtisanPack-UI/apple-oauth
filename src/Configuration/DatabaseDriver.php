<?php

/**
 * Database driver for Apple OAuth credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Configuration;

use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores Apple OAuth app credentials in a database table.
 *
 * The `private_key` and `client_secret` columns hold sensitive material
 * (the raw P-256 signing key and an optional pre-minted JWT respectively);
 * both are stored encrypted using the framework Encrypter. Non-sensitive
 * columns (`client_id`, `team_id`, `key_id`, `redirect_uri`) are stored in
 * cleartext.
 *
 * Values are cached per-request after the first read.
 *
 * @since 1.0.0
 */
class DatabaseDriver implements ConfigurationRepository
{
    /**
     * Fixed value written into the `singleton` column, backed by a unique
     * index in the migration, so the table can hold at most one credential
     * row. Anchoring the upsert on it makes save() atomic against
     * concurrent writers.
     */
    protected const SINGLETON_KEY = 'default';

    protected string $table = 'apple_configurations';

    /**
     * @var array<string, string|null>|null
     */
    protected ?array $cache = null;

    public function __construct(
        protected ConnectionInterface $connection,
        protected Encrypter $encrypter,
    ) {
    }

    public function getClientId(): ?string
    {
        return $this->load()[ 'client_id' ] ?? null;
    }

    public function getTeamId(): ?string
    {
        return $this->load()[ 'team_id' ] ?? null;
    }

    public function getKeyId(): ?string
    {
        return $this->load()[ 'key_id' ] ?? null;
    }

    public function getPrivateKey(): ?string
    {
        return $this->load()[ 'private_key' ] ?? null;
    }

    public function getRedirectUri(): ?string
    {
        return $this->load()[ 'redirect_uri' ] ?? null;
    }

    public function getClientSecret(): ?string
    {
        return $this->load()[ 'client_secret' ] ?? null;
    }

    public function save( array $credentials ): void
    {
        $now = now();

        // upsert() maps to INSERT ... ON CONFLICT DO UPDATE (SQLite/Postgres)
        // and INSERT ... ON DUPLICATE KEY UPDATE (MySQL), so two concurrent
        // initial saves cannot both create a row — the second collides on
        // the `singleton` unique index and falls through to the UPDATE
        // branch. `created_at` is deliberately omitted from the update
        // column list so it is preserved on subsequent saves and only
        // written on the initial insert.
        $this->connection->table( $this->table )->upsert(
            [
                [
                    'singleton'     => self::SINGLETON_KEY,
                    'client_id'     => $credentials[ 'client_id' ] ?? null,
                    'team_id'       => $credentials[ 'team_id' ] ?? null,
                    'key_id'        => $credentials[ 'key_id' ] ?? null,
                    'private_key'   => $this->encryptOrNull( $credentials[ 'private_key' ] ?? null ),
                    'redirect_uri'  => $credentials[ 'redirect_uri' ] ?? null,
                    'client_secret' => $this->encryptOrNull( $credentials[ 'client_secret' ] ?? null ),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ],
            ],
            [ 'singleton' ],
            [
                'client_id',
                'team_id',
                'key_id',
                'private_key',
                'redirect_uri',
                'client_secret',
                'updated_at',
            ],
        );

        $this->cache = null;
    }

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

    /**
     * Encrypt a sensitive value ahead of persistence, preserving null and
     * empty-string inputs so a caller can clear a stored secret by passing
     * either.
     *
     * @since 1.0.0
     */
    protected function encryptOrNull( ?string $value ): ?string
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return $this->encrypter->encryptString( $value );
    }

    /**
     * Read the single credential row and shape it into the normalized array
     * the getters index into.
     *
     * A row whose encrypted columns fail to decrypt is treated as unconfigured
     * for those columns rather than throwing — the most common cause is a
     * rotated `APP_KEY` without a re-encryption pass, and surfacing that as
     * an exception on every request would take the entire integration down.
     * A warning is logged so the misconfiguration is still discoverable.
     *
     * @since 1.0.0
     *
     * @return array<string, string|null>
     */
    protected function load(): array
    {
        if ( null !== $this->cache ) {
            return $this->cache;
        }

        $row = $this->connection->table( $this->table )->first();

        if ( ! $row ) {
            return $this->cache = [];
        }

        return $this->cache = [
            'client_id'     => $row->client_id ?? null,
            'team_id'       => $row->team_id ?? null,
            'key_id'        => $row->key_id ?? null,
            'private_key'   => $this->decryptOrNull( $row->private_key ?? null, 'private_key' ),
            'redirect_uri'  => $row->redirect_uri ?? null,
            'client_secret' => $this->decryptOrNull( $row->client_secret ?? null, 'client_secret' ),
        ];
    }

    /**
     * Decrypt a stored ciphertext, or return null when the column is empty or
     * the ciphertext can no longer be decrypted (typically an `APP_KEY`
     * rotation without a re-encryption pass).
     *
     * @since 1.0.0
     */
    protected function decryptOrNull( ?string $ciphertext, string $column ): ?string
    {
        if ( null === $ciphertext || '' === $ciphertext ) {
            return null;
        }

        try {
            return $this->encrypter->decryptString( $ciphertext );
        } catch ( Throwable $e ) {
            Log::warning(
                'artisanpack-ui/apple-oauth: failed to decrypt stored ' . $column . '; treating as unconfigured. Was APP_KEY rotated without re-encrypting the row?',
                [ 'exception' => $e::class, 'message' => $e->getMessage() ],
            );

            return null;
        }
    }
}
