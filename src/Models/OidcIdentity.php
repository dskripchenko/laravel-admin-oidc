<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The link of a panel user to a provider's subject: the pair (issuer, `sub`)
 * names a person, the email does not — it changes and may be unverified.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $subject
 * @property string $user_type
 * @property int|string $user_id
 * @property string|null $email
 * @property Carbon|null $last_login_at
 */
class OidcIdentity extends Model
{
    protected $table = 'admin_oidc_identities';

    protected $fillable = ['provider_id', 'subject', 'user_type', 'user_id', 'email', 'last_login_at'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    /** @return BelongsTo<OidcProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(OidcProvider::class, 'provider_id');
    }

    /** @return MorphTo<Model, $this> */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }
}
