<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('closed_by_verification_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropIndex(['closed_by_verification_id']);
            $table->dropColumn('closed_by_verification_id');
        });
    }
};
