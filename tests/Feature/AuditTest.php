<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Feature;

use Dskripchenko\LaravelAdmin\Audit\AuditLog;
use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Tests\Support\FakeIdp;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AuditTest extends TestCase
{
    #[Test]
    public function the_audit_row_of_a_sign_in_keeps_no_code_or_state(): void
    {
        config(['admin.audit.log_auth_events' => true]);
        $idp = new FakeIdp;
        $idp->fake();
        Role::create(['name' => 'Super', 'slug' => 'super', 'permissions' => ['*']]);
        $provider = OidcProvider::create([
            'panel' => 'admin', 'name' => 'K', 'issuer' => FakeIdp::ISSUER, 'client_id' => 'panel-client',
            'roles_claim' => 'realm_access.roles', 'role_mapping' => [['value' => 'admins', 'role' => 'super']],
        ]);
        $idp->claims = ['sub' => 's', 'email' => 'a@x.test', 'email_verified' => true, 'realm_access' => ['roles' => ['admins']]];

        $start = $this->get('/admin/sso/redirect/'.$provider->id);
        $state = $idp->authorize((string) $start->headers->get('Location'));
        $this->get('/admin/sso/callback?code=the-authorization-code&state='.urlencode($state))->assertRedirect(url('admin'));

        $row = AuditLog::query()->where('event', 'oidc.sign_in')->firstOrFail();
        $this->assertStringNotContainsString('the-authorization-code', (string) $row->url);
        $this->assertStringNotContainsString($state, (string) $row->url);
        $this->assertStringContainsString('code=[redacted]', (string) $row->url);
    }
}
