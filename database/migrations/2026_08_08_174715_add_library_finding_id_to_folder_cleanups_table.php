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
        Schema::table('folder_cleanups', function (Blueprint $table) {
            $table->foreignId('library_finding_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index(['library_finding_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folder_cleanups', function (Blueprint $table) {
            $table->dropForeign(['library_finding_id']);
        });

        Schema::table('folder_cleanups', function (Blueprint $table) {
            $table->dropIndex(['library_finding_id', 'status']);
            $table->dropColumn('library_finding_id');
        });
    }
};
