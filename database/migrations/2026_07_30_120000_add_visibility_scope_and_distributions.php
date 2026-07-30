<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'visibility_scope')) {
                $table->enum('visibility_scope', ['terbatas', 'unit', 'internal'])
                    ->default('internal')
                    ->after('is_rahasia');
            }
        });

        if (!Schema::hasTable('document_distributions')) {
            Schema::create('document_distributions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
                $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
                $table->string('catatan')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_distributions');

        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'visibility_scope')) {
                $table->dropColumn('visibility_scope');
            }
        });
    }
};
