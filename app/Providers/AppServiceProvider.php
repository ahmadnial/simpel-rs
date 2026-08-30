<?php

namespace App\Providers;

use App\Contracts\OtpSecretProvider;
use App\Contracts\OtpVerifier;
use App\Contracts\EvidenceSigner;
use App\Contracts\ImmutableEvidenceStore;
use App\Contracts\SigningKeyRegistry;
use App\Models\Delegation;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SigningCeremony;
use App\Models\SigningKey;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\HmacOtpVerifier;
use App\Services\DatabaseSigningKeyRegistry;
use App\Services\RuntimeOtpSecretProvider;
use App\Services\SigningOtpService;
use App\Services\TestingOtpSecretProvider;
use App\Services\TestingEvidenceSigner;
use App\Services\UnavailableEvidenceSigner;
use App\Services\TestingImmutableEvidenceStore;
use App\Services\UnavailableImmutableEvidenceStore;
use App\Services\OpenBaoEvidenceSigner;
use App\Services\MinioImmutableEvidenceStore;
use App\Services\AuditChainWriter;
use App\Services\SecurityEventReporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\DatabaseConnected;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OtpSecretProvider::class, function ($app) {
            return $app->environment('testing')
                ? new TestingOtpSecretProvider
                : new RuntimeOtpSecretProvider;
        });
        $this->app->singleton(OtpVerifier::class, HmacOtpVerifier::class);
        $this->app->singleton(TestingEvidenceSigner::class);
        $this->app->singleton(EvidenceSigner::class, function ($app) {
            if ($app->environment('testing')) {
                return $app->make(TestingEvidenceSigner::class);
            }
            return config('tte.providers.signer') === 'openbao'
                ? $app->make(OpenBaoEvidenceSigner::class)
                : new UnavailableEvidenceSigner;
        });
        $this->app->singleton(SigningKeyRegistry::class, function ($app) {
            return $app->environment('testing')
                ? $app->make(TestingEvidenceSigner::class)
                : $app->make(DatabaseSigningKeyRegistry::class);
        });
        $this->app->singleton(ImmutableEvidenceStore::class, function ($app) {
            if ($app->environment('testing')) {
                return new TestingImmutableEvidenceStore;
            }
            return config('tte.providers.immutable_store') === 'minio'
                ? $app->make(MinioImmutableEvidenceStore::class)
                : new UnavailableImmutableEvidenceStore;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.custom');

        User::updated(function (User $user): void {
            if ($user->wasChanged(['email', 'password', 'is_active']) && Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeActive($user, reason: 'account_security_changed');
                $this->failActiveCeremoniesForUser($user->id, 'account_security_changed');
            }
            if ($user->wasChanged(['email', 'password', 'is_active']) && Schema::hasTable('audit_chain_events')) {
                $fields = array_values(array_intersect(['email', 'password', 'is_active'], array_keys($user->getChanges())));
                $this->recordSecurityChange('account_security_changed', User::class, $user->id, $fields, $user->id);
            }
        });
        Document::updated(function (Document $document): void {
            if ($document->wasChanged(['status', 'workflow_template_id', 'current_step', 'judul', 'unit_id']) && Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeForDocument($document, 'document_context_changed');
                $this->failActiveCeremoniesForDocument($document->id, 'document_context_changed');
            }
        });
        DocumentVersion::saved(function (DocumentVersion $version): void {
            if (($version->wasRecentlyCreated || $version->wasChanged(['file_path', 'is_current', 'versi'])) && Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeForDocument($version->document_id, 'document_version_changed');
                $this->failActiveCeremoniesForDocument($version->document_id, 'document_version_changed');
            }
        });
        Delegation::saved(function (Delegation $delegation): void {
            if (Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeActive($delegation->delegasi_id, reason: 'delegation_changed');
                $this->failActiveCeremoniesForUser($delegation->delegasi_id, 'delegation_changed');
            }
            if (Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('delegation_changed', Delegation::class, $delegation->id, array_keys($delegation->getChanges()), $delegation->delegasi_id);
            }
        });
        Delegation::deleted(function (Delegation $delegation): void {
            if (Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeActive($delegation->delegasi_id, reason: 'delegation_changed');
                $this->failActiveCeremoniesForUser($delegation->delegasi_id, 'delegation_changed');
            }
            if (Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('delegation_deleted', Delegation::class, $delegation->id, ['deleted'], $delegation->delegasi_id);
            }
        });
        WorkflowStep::saved(function (WorkflowStep $step): void {
            if (! Schema::hasTable('signature_otp_challenges')) {
                return;
            }
            Document::where('workflow_template_id', $step->workflow_template_id)
                ->where('status', Document::STATUS_MENUNGGU_TTD)
                ->each(function (Document $document): void {
                    app(SigningOtpService::class)->revokeForDocument($document, 'workflow_changed');
                    $this->failActiveCeremoniesForDocument($document->id, 'workflow_changed');
                });
            if (Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('workflow_step_changed', WorkflowStep::class, $step->id, array_keys($step->getChanges()));
            }
        });
        WorkflowStep::deleted(function (WorkflowStep $step): void {
            if (! Schema::hasTable('signature_otp_challenges')) {
                return;
            }
            Document::where('workflow_template_id', $step->workflow_template_id)
                ->where('status', Document::STATUS_MENUNGGU_TTD)
                ->each(function (Document $document): void {
                    app(SigningOtpService::class)->revokeForDocument($document, 'workflow_changed');
                    $this->failActiveCeremoniesForDocument($document->id, 'workflow_changed');
                });
            if (Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('workflow_step_deleted', WorkflowStep::class, $step->id, ['deleted']);
            }
        });
        Event::listen(RoleAttachedEvent::class, function (RoleAttachedEvent $event): void {
            if ($event->model instanceof User && Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeActive($event->model, reason: 'role_changed');
                $this->failActiveCeremoniesForUser($event->model->id, 'role_changed');
            }
            if ($event->model instanceof User && Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('role_attached', User::class, $event->model->id, ['roles'], $event->model->id);
            }
        });
        Event::listen(RoleDetachedEvent::class, function (RoleDetachedEvent $event): void {
            if ($event->model instanceof User && Schema::hasTable('signature_otp_challenges')) {
                app(SigningOtpService::class)->revokeActive($event->model, reason: 'role_changed');
                $this->failActiveCeremoniesForUser($event->model->id, 'role_changed');
            }
            if ($event->model instanceof User && Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('role_detached', User::class, $event->model->id, ['roles'], $event->model->id);
            }
        });
        SigningKey::saved(function (SigningKey $key): void {
            if (Schema::hasTable('audit_chain_events')) {
                $this->recordSecurityChange('institution_signing_key_changed', SigningKey::class, $key->id, array_keys($key->getChanges()));
            }
        });

        Event::listen(DatabaseConnected::class, function (DatabaseConnected $event) {
            if ($event->connection->getDriverName() === 'sqlsrv') {
                $this->setSqlSrvAnsiOptions($event->connection->getPdo());
            }
        });

        Event::listen(StatementPrepared::class, function (StatementPrepared $event) {
            if ($event->connection->getDriverName() === 'sqlsrv') {
                $this->setSqlSrvAnsiOptions($event->connection->getPdo());
            }
        });
    }

    private function setSqlSrvAnsiOptions($pdo): void
    {
        static $configured = [];

        if (!$pdo) {
            return;
        }

        $splId = spl_object_id($pdo);
        if (isset($configured[$splId])) {
            return;
        }
        $configured[$splId] = true;

        try {
            $pdo->exec("
                SET ANSI_NULLS ON;
                SET ANSI_PADDING ON;
                SET ANSI_WARNINGS ON;
                SET CONCAT_NULL_YIELDS_NULL ON;
                SET QUOTED_IDENTIFIER ON;
                SET NUMERIC_ROUNDABORT OFF;
                SET DATEFORMAT ymd;
            ");
        } catch (\Throwable $e) {
            // Ignore if execution fails
        }
    }

    private function failActiveCeremoniesForDocument(int $documentId, string $reason): void
    {
        if (! Schema::hasTable('signing_ceremonies')) {
            return;
        }
        SigningCeremony::where('document_id', $documentId)->whereNotNull('active_key')->update([
            'state' => SigningCeremony::STATE_FAILED,
            'active_key' => null,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    private function failActiveCeremoniesForUser(int $userId, string $reason): void
    {
        if (! Schema::hasTable('signing_ceremonies')) {
            return;
        }
        SigningCeremony::where('intended_actor_id', $userId)->whereNotNull('active_key')->update([
            'state' => SigningCeremony::STATE_FAILED,
            'active_key' => null,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    /** @param array<int,string> $changedFields */
    private function recordSecurityChange(string $event, string $targetType, int $targetId, array $changedFields, ?int $actorId = null): void
    {
        $safeFields = array_values(array_diff($changedFields, ['password', 'remember_token']));
        if (in_array('password', $changedFields, true)) {
            $safeFields[] = 'password_rotated';
        }
        app(AuditChainWriter::class)->append(
            $event,
            ['changed_fields' => array_values(array_unique($safeFields))],
            auth()->id() ?? $actorId,
            $targetType,
            (string) $targetId,
        );
        app(SecurityEventReporter::class)->report($event, [
            'actor_id' => auth()->id() ?? $actorId,
            'changed_fields' => $safeFields,
            'target_id' => $targetId,
            'target_type' => $targetType,
        ], 'warning');
    }
}
