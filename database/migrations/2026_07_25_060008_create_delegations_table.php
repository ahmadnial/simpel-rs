<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pejabat_id')->comment('Pejabat asli yang digantikan');
            $table->unsignedBigInteger('delegasi_id')->comment('Plt/Plh pengganti');
            $table->string('tipe')->default('plt')->comment('plt|plh');
            $table->string('alasan')->nullable();
            $table->date('berlaku_dari');
            $table->date('berlaku_sampai');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('dibuat_oleh');
            $table->timestamps();
            // SQL Server: multiple FK ke users harus noActionOnDelete
            $table->foreign('pejabat_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('delegasi_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('dibuat_oleh')->references('id')->on('users')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
