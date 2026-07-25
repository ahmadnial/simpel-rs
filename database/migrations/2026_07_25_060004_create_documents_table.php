<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('judul');
            $table->unsignedBigInteger('document_type_id');
            $table->unsignedBigInteger('unit_id')->comment('Unit pengusul');
            $table->unsignedBigInteger('pengusul_id')->comment('User yang membuat');
            $table->unsignedBigInteger('workflow_template_id')->nullable();
            $table->string('status')->default('draft')
                ->comment('draft|diajukan|dalam_verifikasi|revisi|menunggu_ttd|ditandatangani|dipublikasikan|diarsipkan|ditolak_batal');
            $table->integer('current_step')->default(0);
            $table->string('nomor_surat')->nullable();
            $table->date('tanggal_surat')->nullable();
            $table->string('perihal')->nullable();
            $table->text('keterangan')->nullable();
            $table->boolean('is_rahasia')->default(false);
            $table->timestamp('diajukan_at')->nullable();
            $table->timestamp('ditandatangani_at')->nullable();
            $table->timestamp('dipublikasikan_at')->nullable();
            $table->timestamp('diarsipkan_at')->nullable();
            $table->string('hash_final')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('document_type_id')->references('id')->on('document_types')->noActionOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->noActionOnDelete();
            $table->foreign('pengusul_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('workflow_template_id')->references('id')->on('workflow_templates')->noActionOnDelete();
        });

        \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX documents_nomor_surat_unique ON documents (nomor_surat) WHERE nomor_surat IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
