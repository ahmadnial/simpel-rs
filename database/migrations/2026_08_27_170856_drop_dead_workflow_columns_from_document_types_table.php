<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom ini pernah ada di admin form (Jenis Naskah) tapi tidak pernah dibaca
     * DocumentService — konfigurasi alur verifikasi/TTE sepenuhnya dikendalikan oleh
     * WorkflowTemplate + WorkflowStep, bukan oleh document_types.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['level_verifikasi', 'penandatangan_default', 'perlu_tte_tersertifikasi']);
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->integer('level_verifikasi')->default(1)->comment('Jumlah level verifikasi');
            $table->string('penandatangan_default')->nullable()->comment('Role default penandatangan');
            $table->boolean('perlu_tte_tersertifikasi')->default(false);
        });
    }
};
