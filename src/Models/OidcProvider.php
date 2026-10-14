<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An OpenID Connect provider a panel signs in with: the panel's own, or a
 * tenant's (`owner`, e.g. a client of the host application).
 *
 * @property int $id
 * @property string $panel
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string $name
 * @property string $issuer
 * @property string $client_id
 * @property string|null $client_secret
 * @property string $scopes
 * @property string $roles_claim
 * @property string $roles_source
 * @property list<mixed>|null $role_mapping
 * @property bool $require_mfa
 * @property bool $link_by_email
 * @property bool $sign_out_at_provider
 * @property bool $enabled
 */
class OidcProvider extends Model
{
    public const ROLES_FROM_ID_TOKEN = 'id_token';

    public const ROLES_FROM_USERINFO = 'userinfo';

    protected $table = 'admin_oidc_providers';

    protected $fillable = [
        'panel', 'owner_type', 'owner_id', 'name', 'issuer', 'client_id', 'client_secret', 'scopes',
        'roles_claim', 'roles_source', 'role_mapping', 'require_mfa', 'link_by_email', 'sign_out_at_provider', 'enabled',
    ];

    /** The secret never leaves the model in an array or JSON. */
    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'role_mapping' => 'array',
            'require_mfa' => 'boolean',
            'link_by_email' => 'boolean',
            'sign_out_at_provider' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<OidcIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class, 'provider_id');
    }

    /** The issuer without a trailing slash — the form discovery and `iss` are compared in. */
    public function normalizedIssuer(): string
    {
        return rtrim($this->issuer, '/');
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        $scopes = preg_split('/[\s,]+/', $this->scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return array_values(array_unique($scopes));
    }

    /**
     * The roles of the panel the claim values map to.
     *
     * @param  list<string>  $values
     * @return list<string> role slugs
     */
    public function mapRoles(array $values): array
    {
        $roles = [];
        foreach ((array) $this->role_mapping as $row) {
            if (! is_array($row)) {
                continue;
            }
            $value = (string) ($row['value'] ?? '');
            $role = (string) ($row['role'] ?? '');
            if ($value !== '' && $role !== '' && in_array($value, $values, true)) {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }
}
