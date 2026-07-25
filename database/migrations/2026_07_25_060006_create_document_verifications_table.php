<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('workflow_step_id')->nullable();
            $table->unsignedBigInteger('verifikator_id');
            $table->integer('level')->comment('Level verifikasi ke-berapa');
            $table->string('status')->default('menunggu')
                ->comment('menunggu|disetujui|revisi|ditolak');
            $table->text('catatan')->nullable();
            $table->timestamp('batas_waktu')->nullable();
            $table->timestamp('direspon_at')->nullable();
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('document_version_id')->references('id')->on('document_versions')->noActionOnDelete();
            $table->foreign('workflow_step_id')->references('id')->on('workflow_steps')->noActionOnDelete();
            $table->foreign('verifikator_id')->references('id')->on('users')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
