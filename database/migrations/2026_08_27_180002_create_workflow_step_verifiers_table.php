<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_step_verifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_step_id');
            $table->string('tipe_pool', 20)->default('role')->comment('role | user');
            $table->string('role_nama', 100)->nullable()->comment('Nama role Spatie jika tipe_pool=role');
            $table->unsignedBigInteger('user_id')->nullable()->comment('User spesifik jika tipe_pool=user');
            $table->timestamps();

            $table->foreign('workflow_step_id')->references('id')->on('workflow_steps')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_verifiers');
    }
};
