<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->unsignedInteger('verification_round')->default(1)->after('level');
            $table->string('activation_reason', 50)->default('submitted')->after('verification_round');
            $table->unsignedBigInteger('reopened_from_verification_id')->nullable()->after('activation_reason');
            $table->index(
                ['document_id', 'document_version_id', 'verification_round', 'level'],
                'document_verifications_round_lookup'
            );
            $table->index('reopened_from_verification_id', 'document_verifications_reopened_from_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropIndex('document_verifications_round_lookup');
            $table->dropIndex('document_verifications_reopened_from_index');
            $table->dropColumn([
                'verification_round',
                'activation_reason',
                'reopened_from_verification_id',
            ]);
        });
    }
};
