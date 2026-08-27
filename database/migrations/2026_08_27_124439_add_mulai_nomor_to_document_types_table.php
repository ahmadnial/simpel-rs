<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedInteger('mulai_nomor')->default(1)->after('format_nomor')->comment('Nomor mulai awal dokumen, berlaku global semua unit (isolasi by klasifikasi)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('mulai_nomor');
        });
    }
};
