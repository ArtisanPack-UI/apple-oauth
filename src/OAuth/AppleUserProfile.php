<?php

/**
 * Apple user profile captured on first authorization.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\OAuth;

/**
 * Immutable value object carrying the user identity Apple releases at first
 * authorization.
 *
 * Apple only returns the `user` form field on the *initial* authorization
 * response — subsequent sign-ins never repeat it. The stable identifier for
 * the user is the `sub` claim inside the id_token; the display name and
 * email address are best-effort, one-shot values that consumers should
 * persist immediately.
 *
 * @since 1.0.0
 */
final class AppleUserProfile
{
    /**
     * @since 1.0.0
     * @since 1.1.0 Added `$displayName`.
     *
     * @param  string       $sub          Apple's stable user identifier (id_token `sub` claim).
     * @param  string|null  $email        Email address released by Apple, if any.
     * @param  string|null  $firstName    Given name released on first authorization only.
     * @param  string|null  $lastName     Family name released on first authorization only.
     * @param  string|null  $displayName  Full display name, when it arrived as one string (an OAuth broker's `account_name`) rather than split into given and family names.
     */
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $displayName = null,
    ) {
    }

    /**
     * Whether Apple released a display name on this authorization.
     *
     * True only on the very first sign-in the user completes for this
     * Services ID; every callback after that returns `false` here.
     *
     * @since 1.0.0
     */
    public function hasName(): bool
    {
        return null !== $this->fullName();
    }

    /**
     * The user's full display name, or null when Apple did not release one.
     *
     * Prefers `$displayName`; otherwise joins the given and family names.
     *
     * @since 1.1.0
     */
    public function fullName(): ?string
    {
        if ( null !== $this->displayName && '' !== $this->displayName ) {
            return $this->displayName;
        }

        $name = trim( ( $this->firstName ?? '' ) . ' ' . ( $this->lastName ?? '' ) );

        return '' === $name ? null : $name;
    }
}
