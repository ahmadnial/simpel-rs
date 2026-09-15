<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->text('deskripsi')->nullable()->change();
        });

        Schema::table('document_verifications', function (Blueprint $table): void {
            $table->text('direset_alasan')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('deskripsi')->nullable()->change();
        });

        Schema::table('document_verifications', function (Blueprint $table): void {
            $table->string('direset_alasan')->nullable()->change();
        });
    }
};
