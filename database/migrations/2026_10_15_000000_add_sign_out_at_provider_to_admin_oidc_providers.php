<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_oidc_providers', function (Blueprint $table): void {
            // Signing out of the panel ends the session at the provider too
            // (RP-initiated logout), when the provider announces where.
            $table->boolean('sign_out_at_provider')->default(true)->after('link_by_email');
        });
    }

    public function down(): void
    {
        Schema::table('admin_oidc_providers', function (Blueprint $table): void {
            $table->dropColumn('sign_out_at_provider');
        });
    }
};
