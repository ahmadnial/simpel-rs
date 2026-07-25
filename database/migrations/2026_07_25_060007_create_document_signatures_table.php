<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('penandatangan_id');
            $table->unsignedBigInteger('delegasi_id')->nullable()->comment('Jika ditandatangani oleh Plt/Plh');
            $table->string('metode_tte')->default('internal')
                ->comment('internal|bssn|peruri|privy|vida');
            $table->string('hash_dokumen', 64)->comment('SHA-256 hash PDF yang ditandatangani');
            $table->string('qr_token', 100)->unique()->comment('Token unik untuk halaman verifikasi QR');
            $table->string('file_signed_path')->nullable()->comment('Path PDF final bertanda tangan');
            $table->json('metadata_tte')->nullable()->comment('Data tambahan dari provider TTE eksternal');
            $table->timestamp('ditandatangani_at');
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('document_version_id')->references('id')->on('document_versions')->noActionOnDelete();
            $table->foreign('penandatangan_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('delegasi_id')->references('id')->on('users')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
