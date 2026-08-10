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
        Schema::table('library_findings', function (Blueprint $table) {
            $table->foreignId('paired_missing_finding_id')
                ->nullable()
                ->after('media_file_id')
                ->constrained('library_findings')
                ->nullOnDelete();
            $table->unique('paired_missing_finding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_findings', function (Blueprint $table) {
            $table->dropForeign(['paired_missing_finding_id']);
        });

        Schema::table('library_findings', function (Blueprint $table) {
            $table->dropUnique(['paired_missing_finding_id']);
            $table->dropColumn('paired_missing_finding_id');
        });
    }
};
