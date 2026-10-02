<?php

/**
 * Apple OAuth app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

use ArtisanPackUI\AppleOAuth\Contracts\ConfigurationRepository;

/**
 * Immutable set of Sign in with Apple app credentials.
 *
 * Lets callers build an {@see AppleClient} from credentials supplied at
 * runtime (for example an OAuth broker that keeps the `.p8` key encrypted
 * in its own database) instead of only the bound
 * {@see ConfigurationRepository}.
 *
 * @since 1.1.0
 */
final class AppleCredentials
{
    /**
     * @since 1.1.0
     *
     * @param  string       $clientId      Services ID (`client_id`).
     * @param  string|null  $teamId        Apple Developer team ID. Required unless `$clientSecret` is set.
     * @param  string|null  $keyId         Key ID of the `.p8` signing key. Required unless `$clientSecret` is set.
     * @param  string|null  $privateKey    The `.p8` private key as inline PEM (or a filesystem path). Required unless `$clientSecret` is set.
     * @param  string|null  $redirectUri   Return URL registered with the Services ID. Only the consent URL and code exchange need it.
     * @param  string|null  $clientSecret  Optional pre-minted `client_secret` JWT; bypasses the ES256 signer when set.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $teamId = null,
        public readonly ?string $keyId = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $redirectUri = null,
        public readonly ?string $clientSecret = null,
    ) {
    }

    /**
     * Build credentials from a configuration repository driver.
     *
     * @since 1.1.0
     */
    public static function fromRepository( ConfigurationRepository $repository ): self
    {
        return new self(
            (string) ( $repository->getClientId() ?? '' ),
            self::nullIfEmpty( $repository->getTeamId() ),
            self::nullIfEmpty( $repository->getKeyId() ),
            self::nullIfEmpty( $repository->getPrivateKey() ),
            self::nullIfEmpty( $repository->getRedirectUri() ),
            self::nullIfEmpty( $repository->getClientSecret() ),
        );
    }

    /**
     * Normalize an empty string to null.
     *
     * @since 1.1.0
     */
    private static function nullIfEmpty( ?string $value ): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }
}
