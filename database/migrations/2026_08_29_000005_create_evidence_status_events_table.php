<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_status_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('signature_evidence_id');
            $table->string('status', 30);
            $table->string('reason');
            $table->string('external_reference', 150);
            $table->uuid('related_evidence_uuid')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('audit_chain_event_id');
            $table->unsignedBigInteger('audit_checkpoint_id');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->foreign('signature_evidence_id')->references('id')->on('signature_evidence')->noActionOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('audit_chain_event_id')->references('id')->on('audit_chain_events')->noActionOnDelete();
            $table->foreign('audit_checkpoint_id')->references('id')->on('audit_checkpoints')->noActionOnDelete();
            $table->unique(['signature_evidence_id', 'status', 'external_reference'], 'evidence_status_idempotency_unique');
            $table->index(['signature_evidence_id', 'occurred_at'], 'evidence_status_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_status_events');
    }
};
