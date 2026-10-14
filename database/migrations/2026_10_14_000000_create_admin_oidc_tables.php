<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_oidc_providers', function (Blueprint $table): void {
            $table->id();
            // The laravel-admin panel the provider signs into.
            $table->string('panel', 64);
            // A tenant's own provider (a client of the host); null — the panel's.
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('issuer', 500);
            $table->string('client_id');
            $table->text('client_secret')->nullable();
            $table->string('scopes', 500)->default('openid email profile');
            // Where the roles are in the claims, dot notation: realm_access.roles.
            $table->string('roles_claim')->default('realm_access.roles');
            $table->string('roles_source', 16)->default('id_token');
            // [{"value": "printable-admin", "role": "super-admin"}, …]
            $table->json('role_mapping')->nullable();
            $table->boolean('require_mfa')->default(false);
            // A first sign-in may take over a local account with the same,
            // provider-verified email.
            $table->boolean('link_by_email')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['panel', 'enabled']);
        });

        Schema::create('admin_oidc_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('admin_oidc_providers')->cascadeOnDelete();
            $table->string('subject');
            $table->morphs('user');
            $table->string('email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_oidc_identities');
        Schema::dropIfExists('admin_oidc_providers');
    }
};
