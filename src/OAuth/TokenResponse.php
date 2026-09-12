<?php

/**
 * Token exchange response.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

use Illuminate\Support\Carbon;

/**
 * Value object describing a successful `authorization_code` exchange with
 * Apple's `/auth/token` endpoint.
 *
 * Encrypted storage of these fields ships with issue #4 — this object is
 * the intermediate shape the OAuth manager returns to callers so consumer
 * apps can wire their own persistence in the meantime.
 *
 * @since 1.0.0
 */
final class TokenResponse
{
    /**
     * @since 1.0.0
     *
     * @param  int|string         $userId        The app-side user id the authorization was initiated for.
     * @param  string             $accessToken   Short-lived access token.
     * @param  string|null        $refreshToken  Long-lived refresh token, when Apple releases one.
     * @param  string|null        $idToken       Raw id_token JWT (unverified).
     * @param  string             $tokenType     Token type; Apple always returns `Bearer`.
     * @param  Carbon|null        $expiresAt     When the access token expires.
     * @param  AppleUserProfile   $profile       Identity extracted from id_token + first-auth `user` payload.
     */
    public function __construct(
        public readonly int|string $userId,
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $idToken,
        public readonly string $tokenType,
        public readonly ?Carbon $expiresAt,
        public readonly AppleUserProfile $profile,
    ) {
    }
}
