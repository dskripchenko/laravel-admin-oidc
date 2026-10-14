<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Feature;

use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdmin\Resource\ActionFailedException;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Resources\OidcProviderResource;
use Dskripchenko\LaravelAdminOidc\Tests\Support\FakeIdp;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;

final class ProviderResourceTest extends TestCase
{
    #[Test]
    public function the_secret_is_encrypted_kept_when_empty_and_never_serialized(): void
    {
        Role::create(['name' => 'Super', 'slug' => 'super', 'permissions' => ['*']]);
        $secret = 'secret-'.bin2hex(random_bytes(4));
        $resource = new OidcProviderResource;
        $provider = new OidcProvider;
        $resource->fillModel($provider, [
            'name' => 'Keycloak', 'panel' => 'admin', 'issuer' => 'https://idp.test/realms/x/', 'client_id' => 'c',
            'client_secret' => $secret, 'roles_claim' => 'realm_access.roles', 'roles_source' => 'id_token',
            'role_mapping' => [['value' => 'admins', 'role' => 'super'], ['value' => '', 'role' => 'super']],
        ]);

        $this->assertSame('https://idp.test/realms/x', $provider->issuer);
        $this->assertSame([['value' => 'admins', 'role' => 'super']], $provider->fresh()->role_mapping);
        $this->assertStringNotContainsString($secret, (string) DB::table('admin_oidc_providers')->value('client_secret'));
        $this->assertArrayNotHasKey('client_secret', $provider->fresh()->toArray());

        $resource->fillModel($provider, ['name' => 'Keycloak 2', 'client_secret' => '', 'role_mapping' => []]);
        $this->assertSame($secret, $provider->fresh()->client_secret);
    }

    #[Test]
    public function a_role_outside_the_catalog_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        (new OidcProviderResource)->fillModel(new OidcProvider, [
            'name' => 'K', 'panel' => 'admin', 'issuer' => 'https://idp.test/r', 'client_id' => 'c',
            'roles_claim' => 'roles', 'role_mapping' => [['value' => 'x', 'role' => 'no-such-role']],
        ]);
    }

    #[Test]
    public function check_reads_discovery_and_keys_or_says_why_not(): void
    {
        (new FakeIdp)->fake();
        $good = OidcProvider::create(['panel' => 'admin', 'name' => 'K', 'issuer' => FakeIdp::ISSUER, 'client_id' => 'c']);
        $this->assertSame(1, (new OidcProviderResource)->check([$good->id]));

        Http::fake(['wrong.test/*' => Http::response(['issuer' => 'https://other.test'])]);
        $bad = OidcProvider::create(['panel' => 'admin', 'name' => 'Wrong', 'issuer' => 'https://wrong.test/r', 'client_id' => 'c']);
        $this->expectException(ActionFailedException::class);
        (new OidcProviderResource)->check([$bad->id]);
    }
}
