<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->uuid('signing_ceremony_id')->nullable();
            $table->char('pdf_hash', 64);
            $table->char('manifest_draft_hash', 64);
            $table->char('nonce_hash', 64);
            $table->char('session_id_hash', 64);
            $table->string('action', 50);
            $table->string('policy_version', 50);
            $table->char('otp_verifier', 64);
            $table->string('masked_destination');
            $table->char('destination_hash', 64);
            $table->unsignedInteger('resend_generation')->default(1);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->string('state', 30);
            $table->char('active_binding_key', 64)->nullable();
            $table->uuid('correlation_id');
            $table->char('source_ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->boolean('authorization_result')->default(false);
            $table->unsignedInteger('reauthentication_age_seconds')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->noActionOnDelete();
            $table->foreign('document_version_id')->references('id')->on('document_versions')->noActionOnDelete();
            $table->index(['user_id', 'document_id', 'document_version_id', 'action'], 'signature_otp_binding_lookup');
            $table->index(['user_id', 'requested_at'], 'signature_otp_rate_lookup');
            $table->index(['state', 'expires_at'], 'signature_otp_expiry_lookup');
        });

        DB::statement('CREATE UNIQUE INDEX signature_otp_one_active_binding ON signature_otp_challenges (active_binding_key) WHERE active_binding_key IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_otp_challenges');
    }
};
