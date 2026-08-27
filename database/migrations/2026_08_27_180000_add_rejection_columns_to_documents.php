<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('ditolak_ttd_alasan')->nullable()->comment('Alasan penolakan dari penandatangan');
            $table->timestamp('ditolak_ttd_at')->nullable();
            $table->unsignedBigInteger('ditolak_ttd_oleh')->nullable();
            $table->foreign('ditolak_ttd_oleh')->references('id')->on('users')->noActionOnDelete();
        });

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->string('direset_alasan')->nullable()->comment('Alasan reset karena tolak dari penandatangan atau diturunkan');
            $table->timestamp('direset_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropColumn(['direset_alasan', 'direset_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['ditolak_ttd_oleh']);
            $table->dropColumn(['ditolak_ttd_alasan', 'ditolak_ttd_at', 'ditolak_ttd_oleh']);
        });
    }
};
