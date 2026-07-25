<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique()->comment('Kode jenis, mis: SK, SPO, SE, ND');
            $table->string('nama');
            $table->string('singkatan', 20);
            $table->text('deskripsi')->nullable();
            $table->string('format_nomor')->default('{urut}/{kode}/{unit}/{rs}/{bulan_romawi}/{tahun}')
                ->comment('Template format nomor surat');
            $table->integer('level_verifikasi')->default(1)->comment('Jumlah level verifikasi');
            $table->string('penandatangan_default')->nullable()->comment('Role default penandatangan');
            $table->boolean('perlu_tte_tersertifikasi')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
