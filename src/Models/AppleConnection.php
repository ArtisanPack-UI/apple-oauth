<?php

/**
 * Apple connection model.
 *
 * @package    ArtisanPack_UI
 * @subpackage AppleOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\AppleOAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a single connected Apple account for a user.
 *
 * Access, refresh, and id tokens are stored encrypted via Eloquent's
 * `encrypted` cast (Laravel's `Crypt` facade). Scopes are stored as a
 * JSON array. Status is one of `connected` or `disconnected`.
 *
 * @since 1.0.0
 *
 * @property int                             $id
 * @property int                             $user_id
 * @property string|null                     $apple_user_id
 * @property string|null                     $email
 * @property string|null                     $access_token
 * @property string|null                     $refresh_token
 * @property string|null                     $id_token
 * @property string                          $token_type
 * @property array<int, string>|null         $scopes
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string                          $status
 * @property string|null                     $disconnect_reason
 */
class AppleConnection extends Model
{
    public const STATUS_CONNECTED    = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $table = 'apple_connections';

    protected $fillable = [
        'user_id',
        'apple_user_id',
        'email',
        'access_token',
        'refresh_token',
        'id_token',
        'token_type',
        'scopes',
        'expires_at',
        'status',
        'disconnect_reason',
    ];

    /**
     * The user that owns this Apple connection.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Model, self>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config( 'apple-oauth.user_model', 'App\\Models\\User' );

        return $this->belongsTo( $userModel, 'user_id' );
    }

    /**
     * Whether the stored access token is expired (or expires within 60s).
     *
     * @since 1.0.0
     */
    public function isExpired(): bool
    {
        if ( null === $this->expires_at ) {
            return true;
        }

        return $this->expires_at->copy()->subSeconds( 60 )->isPast();
    }

    /**
     * Whether the connection is usable for API calls.
     *
     * @since 1.0.0
     */
    public function isConnected(): bool
    {
        return self::STATUS_CONNECTED === $this->status;
    }

    /**
     * The scopes granted by Apple to this connection, normalized to a list.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    public function grantedScopes(): array
    {
        $scopes = $this->scopes;

        if ( ! is_array( $scopes ) ) {
            return [];
        }

        return array_values( array_map( 'strval', $scopes ) );
    }

    /**
     * Mark this connection disconnected (e.g. after a refresh failure).
     *
     * @since 1.0.0
     */
    public function markDisconnected( ?string $reason = null ): void
    {
        $this->status            = self::STATUS_DISCONNECTED;
        $this->disconnect_reason = $reason;
        $this->save();
    }

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
}
