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
        Schema::table('media_items', function (Blueprint $table) {
            $table->foreignId('current_media_file_id')
                ->nullable()
                ->unique()
                ->constrained('media_files')
                ->restrictOnDelete();
        });

        Schema::table('uploads', function (Blueprint $table) {
            $table->foreign('replaces_media_file_id')
                ->references('id')
                ->on('media_files')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropForeign(['replaces_media_file_id']);
        });

        Schema::table('media_items', function (Blueprint $table) {
            $table->dropForeign(['current_media_file_id']);
            $table->dropUnique(['current_media_file_id']);
            $table->dropColumn('current_media_file_id');
        });
    }
};
