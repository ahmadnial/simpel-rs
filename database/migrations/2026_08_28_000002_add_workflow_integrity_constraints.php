<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->unique(['document_id', 'versi'], 'document_versions_document_version_unique');
        });
        // Riwayat lama boleh memiliki lebih dari satu tiket untuk aktor/step yang sama akibat
        // reset antar-siklus. Yang harus unik hanyalah tiket AKTIF agar dua request konkuren
        // tidak membuat dua antrian menunggu identik dan audit trail lama tidak perlu dihapus.
        DB::statement(
            "CREATE UNIQUE INDEX document_verifications_active_ticket_unique
             ON document_verifications (document_id, document_version_id, workflow_step_id, verifikator_id, level)
             WHERE status = 'menunggu'"
        );
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->unique('document_id', 'document_signatures_document_unique');
        });
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->unique(['workflow_template_id', 'urutan'], 'workflow_steps_template_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', fn (Blueprint $table) => $table->dropUnique('workflow_steps_template_order_unique'));
        Schema::table('document_signatures', fn (Blueprint $table) => $table->dropUnique('document_signatures_document_unique'));
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX document_verifications_active_ticket_unique ON document_verifications');
        } else {
            DB::statement('DROP INDEX document_verifications_active_ticket_unique');
        }
        Schema::table('document_versions', fn (Blueprint $table) => $table->dropUnique('document_versions_document_version_unique'));
    }
};
