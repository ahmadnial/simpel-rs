<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable()->comment('Snapshot nama user saat aksi');
            $table->string('aksi', 100)->comment('mis: upload, submit, approve, reject, sign, publish');
            $table->string('model_type')->nullable()->comment('Nama model yang terpengaruh');
            $table->unsignedBigInteger('model_id')->nullable()->comment('ID record yang terpengaruh');
            $table->string('deskripsi')->nullable();
            $table->json('data_lama')->nullable()->comment('Nilai field sebelum perubahan');
            $table->json('data_baru')->nullable()->comment('Nilai field setelah perubahan');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // Tidak ada updated_at — immutable log
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
