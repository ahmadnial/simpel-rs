<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->integer('versi')->default(1);
            $table->string('file_path')->comment('Path file .docx di storage');
            $table->string('file_pdf_path')->nullable()->comment('Path file PDF final');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->text('catatan')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
