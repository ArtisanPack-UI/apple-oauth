<?php

/**
 * CMS Framework Settings driver for Apple OAuth credentials.
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores Apple OAuth app credentials in the CMS framework's Settings module.
 *
 * Only registered when `artisanpack-ui/cms-framework` is installed. Delegates
 * reads and writes to `apGetSetting()` / `apUpdateSetting()` (with a matching
 * `apRegisterSetting()` at boot time) so credentials live alongside every other
 * site-level setting the CMS manages. The `private_key` and `client_secret`
 * values are encrypted using the framework Encrypter before being written to
 * the Settings table; non-sensitive keys (`client_id`, `team_id`, `key_id`,
 * `redirect_uri`) are stored in cleartext.
 *
 * @since 1.0.0
 */
class CmsSettingsDriver implements ConfigurationRepository
{
    public const KEY_CLIENT_ID = 'artisanpack_apple_oauth_client_id';

    public const KEY_TEAM_ID = 'artisanpack_apple_oauth_team_id';

    public const KEY_KEY_ID = 'artisanpack_apple_oauth_key_id';

    public const KEY_PRIVATE_KEY = 'artisanpack_apple_oauth_private_key';

    public const KEY_REDIRECT_URI = 'artisanpack_apple_oauth_redirect_uri';

    public const KEY_CLIENT_SECRET = 'artisanpack_apple_oauth_client_secret';

    /**
     * @var array<string, string|null>|null
     */
    protected ?array $cache = null;

    public function __construct( protected Encrypter $encrypter )
    {
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
        // Pass plaintext through. Encryption for the sensitive keys is owned
        // by the sanitize callbacks that AppleOAuthServiceProvider registers
        // with the CMS framework, so both this write path and any operator
        // saving through the Settings UI persist the same ciphertext shape.
        apUpdateSetting( self::KEY_CLIENT_ID, $credentials[ 'client_id' ] ?? null );
        apUpdateSetting( self::KEY_TEAM_ID, $credentials[ 'team_id' ] ?? null );
        apUpdateSetting( self::KEY_KEY_ID, $credentials[ 'key_id' ] ?? null );
        apUpdateSetting( self::KEY_PRIVATE_KEY, $credentials[ 'private_key' ] ?? null );
        apUpdateSetting( self::KEY_REDIRECT_URI, $credentials[ 'redirect_uri' ] ?? null );
        apUpdateSetting( self::KEY_CLIENT_SECRET, $credentials[ 'client_secret' ] ?? null );

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
     * Clear the per-request cache; primarily for tests.
     *
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, string|null>
     */
    protected function load(): array
    {
        if ( null !== $this->cache ) {
            return $this->cache;
        }

        $clientId    = apGetSetting( self::KEY_CLIENT_ID );
        $teamId      = apGetSetting( self::KEY_TEAM_ID );
        $keyId       = apGetSetting( self::KEY_KEY_ID );
        $redirectUri = apGetSetting( self::KEY_REDIRECT_URI );

        return $this->cache = [
            'client_id'     => $this->stringOrNull( $clientId ),
            'team_id'       => $this->stringOrNull( $teamId ),
            'key_id'        => $this->stringOrNull( $keyId ),
            'private_key'   => $this->decryptOrNull( apGetSetting( self::KEY_PRIVATE_KEY ), 'private_key' ),
            'redirect_uri'  => $this->stringOrNull( $redirectUri ),
            'client_secret' => $this->decryptOrNull( apGetSetting( self::KEY_CLIENT_SECRET ), 'client_secret' ),
        ];
    }

    /**
     * Normalize a raw setting value to a non-empty string or null.
     *
     * @since 1.0.0
     */
    protected function stringOrNull( mixed $value ): ?string
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Decrypt a stored ciphertext, or return null when the setting is empty or
     * the ciphertext can no longer be decrypted (typically an `APP_KEY`
     * rotation without a re-encryption pass).
     *
     * @since 1.0.0
     */
    protected function decryptOrNull( mixed $ciphertext, string $key ): ?string
    {
        if ( null === $ciphertext || '' === $ciphertext || ! is_string( $ciphertext ) ) {
            return null;
        }

        try {
            return $this->encrypter->decryptString( $ciphertext );
        } catch ( Throwable $e ) {
            Log::warning(
                'artisanpack-ui/apple-oauth: failed to decrypt CMS-stored ' . $key . '; treating as unconfigured. Was APP_KEY rotated without re-encrypting the setting?',
                [ 'exception' => $e::class, 'message' => $e->getMessage() ],
            );

            return null;
        }
    }
}
