<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->integer('min_approval')->default(1)->comment('Jumlah minimum persetujuan yang dibutuhkan pada step ini');
            $table->string('mode_verifikasi', 20)->default('sequential')->comment('sequential | parallel_quorum');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['min_approval', 'mode_verifikasi']);
        });
    }
};
