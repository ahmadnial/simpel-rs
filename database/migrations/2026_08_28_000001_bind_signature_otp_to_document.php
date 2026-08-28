<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('otp_hash')->nullable();
            $table->unsignedBigInteger('otp_document_id')->nullable();
            $table->foreign('otp_document_id')->references('id')->on('documents')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['otp_document_id']);
            $table->dropColumn(['otp_hash', 'otp_document_id']);
        });
    }
};
