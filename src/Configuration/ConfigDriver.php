<?php

/**
 * Config-file driver for Apple OAuth credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Configuration;

use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Reads Apple OAuth app credentials from the Laravel config repository.
 *
 * This driver is read-only; the values are managed via config/env files.
 *
 * @since 1.0.0
 */
class ConfigDriver implements ConfigurationRepository
{
    public function __construct( protected ConfigRepository $config )
    {
    }

    public function getClientId(): ?string
    {
        return $this->nullIfEmpty( $this->config->get( 'apple-oauth.client_id' ) );
    }

    public function getTeamId(): ?string
    {
        return $this->nullIfEmpty( $this->config->get( 'apple-oauth.team_id' ) );
    }

    public function getKeyId(): ?string
    {
        return $this->nullIfEmpty( $this->config->get( 'apple-oauth.key_id' ) );
    }

    public function getPrivateKey(): ?string
    {
        return $this->nullIfEmpty( $this->config->get( 'apple-oauth.private_key' ) );
    }

    public function getRedirectUri(): ?string
    {
        return $this->nullIfEmpty( $this->config->get( 'apple-oauth.redirect_uri' ) );
    }

    public function getClientSecret(): ?string
    {
        return $this->nullIfEmpty( $this->config->get( 'apple-oauth.client_secret' ) );
    }

    public function save( array $credentials ): void
    {
        throw new RuntimeException(
            'The config driver is read-only. Switch to the database driver to persist credentials.',
        );
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
     * Normalize the config repository's `''` and `false` returns to null so
     * downstream `empty()` checks and null-coalescing operators behave
     * consistently regardless of how a value was cleared.
     *
     * @since 1.0.0
     */
    protected function nullIfEmpty( mixed $value ): ?string
    {
        if ( null === $value || '' === $value || false === $value ) {
            return null;
        }

        return (string) $value;
    }
}
