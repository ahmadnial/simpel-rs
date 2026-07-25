<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_type_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->smallInteger('tahun')->comment('Tahun (mis: 2026)');
            $table->unsignedInteger('nomor_terakhir')->default(0)->comment('Counter atomic');
            $table->timestamps();
            $table->foreign('document_type_id')->references('id')->on('document_types')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->noActionOnDelete();
            $table->unique(['document_type_id', 'unit_id', 'tahun'], 'numbering_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
    }
};
