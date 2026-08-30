<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 100)->unique();
            $table->string('algorithm', 30);
            $table->string('purpose', 60);
            $table->string('provider_reference')->nullable();
            $table->longText('public_key');
            $table->char('fingerprint', 64)->unique();
            $table->string('status', 30);
            $table->timestamp('activated_at');
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('compromised_at')->nullable();
            $table->string('reason')->nullable();
            $table->string('policy_version', 50);
            $table->json('approval_references')->nullable();
            $table->timestamps();
        });

        Schema::table('signature_evidence', function (Blueprint $table) {
            $table->string('signature_algorithm', 30)->nullable();
            $table->string('signing_key_id', 100)->nullable();
            $table->char('signing_key_fingerprint', 64)->nullable();
            $table->text('institution_signature')->nullable();
            $table->string('bundle_path')->nullable();
            $table->char('bundle_hash', 64)->nullable();
            $table->unsignedBigInteger('bundle_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signature_evidence', function (Blueprint $table) {
            $table->dropColumn([
                'signature_algorithm', 'signing_key_id', 'signing_key_fingerprint',
                'institution_signature', 'bundle_path', 'bundle_hash', 'bundle_size',
            ]);
        });
        Schema::dropIfExists('signing_keys');
    }
};
