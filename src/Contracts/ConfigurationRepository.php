<?php

/**
 * Configuration repository contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Contracts;

/**
 * Contract for Apple OAuth credential storage drivers.
 *
 * Implementations back either config/env files or the database. OAuth tokens
 * are NOT stored here — see the {@see \ArtisanPackUI\AppleOAuth\Models\AppleConnection}
 * model for per-user access, refresh, and id tokens.
 *
 * The credentials modelled here are the ones needed to bootstrap the flow:
 * the Services ID (`client_id`), the Apple Developer `team_id`, the `key_id`
 * of the signing key, the `private_key` contents or path, the OAuth
 * `redirect_uri`, and an optional pre-minted static `client_secret` JWT for
 * local testing.
 *
 * @since 1.0.0
 */
interface ConfigurationRepository
{
    /**
     * Get the OAuth Services ID (client_id).
     *
     * @since 1.0.0
     */
    public function getClientId(): ?string;

    /**
     * Get the Apple Developer team_id.
     *
     * @since 1.0.0
     */
    public function getTeamId(): ?string;

    /**
     * Get the key_id of the private key used to sign the client-secret JWT.
     *
     * @since 1.0.0
     */
    public function getKeyId(): ?string;

    /**
     * Get the private key material — either an inline PEM string or an
     * absolute filesystem path pointing at a `.p8` file.
     *
     * @since 1.0.0
     */
    public function getPrivateKey(): ?string;

    /**
     * Get the OAuth redirect URI registered with the Services ID.
     *
     * @since 1.0.0
     */
    public function getRedirectUri(): ?string;

    /**
     * Get the optional pre-minted client_secret JWT used to bypass the ES256
     * signer during local testing. Returns null when the signer should mint
     * the client_secret on demand.
     *
     * @since 1.0.0
     */
    public function getClientSecret(): ?string;

    /**
     * Persist a full credential set.
     *
     * Drivers that are read-only (like the config driver) may throw a
     * {@see \RuntimeException}. Accepted keys: `client_id`, `team_id`,
     * `key_id`, `private_key`, `redirect_uri`, `client_secret`. Any omitted
     * key clears the corresponding stored value.
     *
     * @since 1.0.0
     *
     * @param  array<string, string|null>  $credentials
     */
    public function save( array $credentials ): void;

    /**
     * Whether the repository has a usable credential set.
     *
     * `client_id` and `redirect_uri` are always required. In addition either
     * a pre-minted `client_secret` OR the full signer inputs (`team_id`,
     * `key_id`, `private_key`) must be present.
     *
     * @since 1.0.0
     */
    public function isConfigured(): bool;
}
