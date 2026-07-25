<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->unsignedBigInteger('document_type_id');
            $table->unsignedBigInteger('unit_id')->nullable()->comment('Null = berlaku untuk semua unit');
            $table->text('deskripsi')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('document_type_id')->references('id')->on('document_types')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->noActionOnDelete();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->integer('urutan')->comment('1, 2, 3 dst — urutan verifikasi');
            $table->string('nama_tahap')->comment('mis: Verifikasi Kabag, Verifikasi Komite');
            $table->string('tipe')->default('verifikasi')
                ->comment('verifikasi | penandatangan');
            $table->string('role_nama')->nullable()->comment('Role Spatie yang berwenang di tahap ini');
            $table->integer('sla_hari_kerja')->default(2)->comment('Batas waktu respon dalam hari kerja');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_templates');
    }
};
