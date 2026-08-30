<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_streams', function (Blueprint $table) {
            $table->string('stream_id', 100)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_event_hash', 64)->default('0000000000000000000000000000000000000000000000000000000000000000');
            $table->timestamps();
        });

        Schema::create('audit_chain_events', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id', 100);
            $table->unsignedBigInteger('sequence');
            $table->char('previous_event_hash', 64);
            $table->char('payload_hash', 64);
            $table->char('event_hash', 64)->unique();
            $table->string('event_type', 100);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->uuid('correlation_id');
            // Selalu non-null agar unique constraint aman pada SQL Server (yang hanya
            // mengizinkan satu NULL per kombinasi unique), namun event non-idempotent
            // memperoleh random operation key dari writer.
            $table->char('idempotency_key', 64);
            $table->longText('canonical_payload');
            $table->json('metadata')->nullable();
            $table->timestamp('application_time');
            $table->timestamp('database_time');
            $table->string('result', 30);
            $table->timestamp('created_at');

            $table->unique(['stream_id', 'sequence'], 'audit_chain_stream_sequence_unique');
            $table->unique(['stream_id', 'idempotency_key'], 'audit_chain_stream_idempotency_unique');
            $table->foreign('stream_id')->references('stream_id')->on('audit_chain_streams')->noActionOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->noActionOnDelete();
            $table->index(['target_type', 'target_id'], 'audit_chain_target_lookup');
        });

        Schema::create('audit_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('stream_id', 100);
            $table->unsignedBigInteger('sequence_start');
            $table->unsignedBigInteger('sequence_end');
            $table->unsignedBigInteger('event_count');
            $table->char('last_event_hash', 64);
            $table->char('checkpoint_hash', 64);
            $table->longText('canonical_checkpoint');
            $table->string('signature_algorithm', 30);
            $table->string('signing_key_id', 100);
            $table->char('signing_key_fingerprint', 64);
            $table->text('signature');
            $table->json('storage_receipt')->nullable();
            $table->timestamp('created_at');

            $table->unique(['stream_id', 'sequence_end'], 'audit_checkpoint_stream_end_unique');
        });

        Schema::create('evidence_storage_copies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signature_evidence_id')->nullable();
            $table->string('evidence_uuid');
            $table->string('artifact_type', 40);
            $table->string('storage_provider', 60);
            $table->string('bucket_logical_id', 100);
            $table->string('object_key');
            $table->string('object_version_id');
            $table->char('checksum', 64);
            $table->unsignedBigInteger('size');
            $table->string('retention_mode', 30);
            $table->timestamp('retention_until');
            $table->timestamp('verified_at');
            $table->string('state', 30);
            $table->timestamps();

            $table->foreign('signature_evidence_id')->references('id')->on('signature_evidence')->noActionOnDelete();
            $table->unique(['storage_provider', 'object_key', 'object_version_id'], 'evidence_storage_object_version_unique');
            $table->index(['evidence_uuid', 'artifact_type'], 'evidence_storage_evidence_artifact');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_storage_copies');
        Schema::dropIfExists('audit_checkpoints');
        Schema::dropIfExists('audit_chain_events');
        Schema::dropIfExists('audit_chain_streams');
    }
};
