<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom untuk fitur Unpublish / Penarikan Naskah Dinas.
     */
    public function up(): void
    {
        $hasDitarik = Schema::hasColumn('documents', 'ditarik_at');
        $hasAlasan = Schema::hasColumn('documents', 'alasan_penarikan');
        $hasPengganti = Schema::hasColumn('documents', 'pengganti_document_id');

        if (!$hasDitarik || !$hasAlasan || !$hasPengganti) {
            Schema::table('documents', function (Blueprint $table) use ($hasDitarik, $hasAlasan, $hasPengganti) {
                if (!$hasDitarik) {
                    $table->timestamp('ditarik_at')->nullable();
                }
                if (!$hasAlasan) {
                    $table->text('alasan_penarikan')->nullable();
                }
                if (!$hasPengganti) {
                    $table->unsignedBigInteger('pengganti_document_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'pengganti_document_id')) {
                $table->dropColumn('pengganti_document_id');
            }
            if (Schema::hasColumn('documents', 'alasan_penarikan')) {
                $table->dropColumn('alasan_penarikan');
            }
            if (Schema::hasColumn('documents', 'ditarik_at')) {
                $table->dropColumn('ditarik_at');
            }
        });
    }
};

