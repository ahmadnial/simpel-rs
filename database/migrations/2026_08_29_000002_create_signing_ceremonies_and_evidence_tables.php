<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('uuid')->nullable();
        });
        DB::table('documents')->whereNull('uuid')->orderBy('id')->chunkById(100, function ($documents): void {
            foreach ($documents as $document) {
                DB::table('documents')->where('id', $document->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->unique('uuid', 'documents_uuid_unique');
        });

        Schema::create('signing_ceremonies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('evidence_uuid')->unique();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('intended_actor_id');
            $table->string('intended_role');
            $table->unsignedBigInteger('delegation_id')->nullable();
            $table->unsignedBigInteger('otp_challenge_id')->nullable();
            $table->char('session_id_hash', 64);
            $table->char('nonce_hash', 64);
            $table->char('manifest_draft_hash', 64)->nullable();
            $table->char('candidate_pdf_hash', 64)->nullable();
            $table->unsignedBigInteger('candidate_pdf_size')->nullable();
            $table->string('candidate_pdf_path')->nullable();
            $table->string('reserved_number')->nullable();
            $table->string('qr_token', 100)->unique();
            $table->string('state', 40);
            $table->char('active_key', 64)->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->timestamp('reauthenticated_at');
            $table->boolean('authorization_result')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->noActionOnDelete();
            $table->foreign('document_version_id')->references('id')->on('document_versions')->noActionOnDelete();
            $table->foreign('intended_actor_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('delegation_id')->references('id')->on('delegations')->noActionOnDelete();
            $table->foreign('otp_challenge_id')->references('id')->on('signature_otp_challenges')->noActionOnDelete();
            $table->index(['document_id', 'document_version_id', 'state'], 'signing_ceremony_document_state');
        });
        DB::statement('CREATE UNIQUE INDEX signing_ceremony_one_active ON signing_ceremonies (active_key) WHERE active_key IS NOT NULL');

        Schema::create('signature_evidence', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('schema_version', 30);
            $table->string('assurance_profile', 50);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('signing_ceremony_id')->unique();
            $table->unsignedBigInteger('otp_challenge_id')->unique();
            $table->char('pdf_hash', 64);
            $table->unsignedBigInteger('pdf_size');
            $table->string('pdf_path');
            $table->longText('canonical_manifest');
            $table->char('manifest_hash', 64);
            $table->json('document_snapshot');
            $table->json('signer_snapshot');
            $table->json('workflow_snapshot');
            $table->json('delegation_snapshot')->nullable();
            $table->json('otp_receipt');
            $table->string('state', 40);
            $table->timestamp('sealed_at')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->noActionOnDelete();
            $table->foreign('document_version_id')->references('id')->on('document_versions')->noActionOnDelete();
            $table->foreign('signing_ceremony_id')->references('id')->on('signing_ceremonies')->noActionOnDelete();
            $table->foreign('otp_challenge_id')->references('id')->on('signature_otp_challenges')->noActionOnDelete();
            $table->unique(['document_id', 'document_version_id'], 'signature_evidence_document_version_unique');
        });

        Schema::create('signing_outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('signing_ceremony_id');
            $table->string('type', 60);
            $table->char('idempotency_key', 64)->unique();
            $table->json('payload');
            $table->string('state', 30)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->foreign('signing_ceremony_id')->references('id')->on('signing_ceremonies')->noActionOnDelete();
            $table->index(['state', 'available_at'], 'signing_outbox_pending');
        });

        Schema::table('document_signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('signature_evidence_id')->nullable();
            $table->unsignedBigInteger('otp_challenge_id')->nullable();
            $table->string('assurance_profile', 50)->default('legacy_internal_v1');
            $table->foreign('signature_evidence_id')->references('id')->on('signature_evidence')->noActionOnDelete();
            $table->foreign('otp_challenge_id')->references('id')->on('signature_otp_challenges')->noActionOnDelete();
        });
        // SQL Server unique constraint biasa hanya menerima satu NULL. Signature
        // legacy harus boleh tetap NULL, jadi gunakan filtered unique index.
        DB::statement('CREATE UNIQUE INDEX document_signatures_evidence_unique ON document_signatures (signature_evidence_id) WHERE signature_evidence_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX document_signatures_evidence_unique ON document_signatures');
        } else {
            DB::statement('DROP INDEX document_signatures_evidence_unique');
        }
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->dropForeign(['signature_evidence_id']);
            $table->dropForeign(['otp_challenge_id']);
            $table->dropColumn(['signature_evidence_id', 'otp_challenge_id', 'assurance_profile']);
        });
        Schema::dropIfExists('signing_outbox_messages');
        Schema::dropIfExists('signature_evidence');
        Schema::dropIfExists('signing_ceremonies');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
