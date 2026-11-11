<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite table rebuilds during earlier column changes lost this predicate.
        // SQL Server already has the correct filtered index; do not alter it.
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS document_verifications_active_ticket_unique');
        DB::statement("CREATE UNIQUE INDEX document_verifications_active_ticket_unique
            ON document_verifications (document_id, document_version_id, workflow_step_id, verifikator_id, level)
            WHERE status = 'menunggu'");
    }

    public function down(): void
    {
        // Preserve the original invariant: historical decisions are not unique tickets.
    }
};
