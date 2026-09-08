<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('decided_by_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropIndex(['decided_by_user_id']);
            $table->dropColumn('decided_by_user_id');
        });
    }
};
