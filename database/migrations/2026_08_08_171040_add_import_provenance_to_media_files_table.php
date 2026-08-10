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
        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('source_upload_id')->nullable()->change();
            $table->foreignId('imported_by_user_id')
                ->nullable()
                ->after('source_upload_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->json('import_provenance')->nullable()->after('imported_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropForeign(['imported_by_user_id']);
            $table->dropColumn(['imported_by_user_id', 'import_provenance']);
            $table->foreignId('source_upload_id')->nullable(false)->change();
        });
    }
};
