<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menggantikan workflow_templates.unit_id (single, nullable) dengan relasi many-to-many:
     * satu template bisa dibatasi ke lebih dari satu unit sekaligus (pivot kosong = berlaku
     * untuk semua unit/instalasi/tim/komite, perilaku default).
     */
    public function up(): void
    {
        Schema::create('workflow_template_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->foreign('unit_id')->references('id')->on('units')->cascadeOnDelete();
            $table->unique(['workflow_template_id', 'unit_id']);
        });

        // Migrasikan data unit_id lama (single) jadi 1 baris pivot, lalu hapus kolom lama.
        DB::table('workflow_templates')
            ->whereNotNull('unit_id')
            ->select('id', 'unit_id')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('workflow_template_units')->insert([
                    'workflow_template_id' => $row->id,
                    'unit_id'              => $row->unit_id,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            });

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable()->comment('Null = berlaku untuk semua unit');
            $table->foreign('unit_id')->references('id')->on('units')->noActionOnDelete();
        });

        // Best-effort: kalau template dibatasi ke lebih dari 1 unit, cuma unit pertama yang
        // terbawa balik ke kolom tunggal (skema lama tidak bisa menampung lebih dari 1).
        DB::table('workflow_template_units')
            ->select('workflow_template_id', 'unit_id')
            ->orderBy('workflow_template_id')
            ->orderBy('unit_id')
            ->get()
            ->unique('workflow_template_id')
            ->each(function ($row) {
                DB::table('workflow_templates')
                    ->where('id', $row->workflow_template_id)
                    ->update(['unit_id' => $row->unit_id]);
            });

        Schema::dropIfExists('workflow_template_units');
    }
};
