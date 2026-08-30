<?php

namespace Tests\Feature;

use App\Models\AuditChainEvent;
use App\Models\User;
use App\Services\AuditChainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_password_active_state_and_role_changes_are_audited_without_secret_values(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
        $user = User::factory()->create();

        $user->update(['email' => 'changed@example.test', 'password' => 'new-secret-value', 'is_active' => false]);
        $user->assignRole('penandatangan');

        $account = AuditChainEvent::where('event_type', 'account_security_changed')->firstOrFail();
        $role = AuditChainEvent::where('event_type', 'role_attached')->firstOrFail();
        $this->assertStringNotContainsString('changed@example.test', $account->canonical_payload);
        $this->assertStringNotContainsString('new-secret-value', $account->canonical_payload);
        $this->assertStringContainsString('password_rotated', $account->canonical_payload);
        $this->assertSame((string) $user->id, $role->target_id);
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
    }
}
