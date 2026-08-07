<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropUnique(['disk_id', 'relative_path']);
            $table->char('active_path_key', 64)->nullable()->after('relative_path');
        });

        DB::table('media_files')
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->orderBy('id')
            ->eachById(function (object $mediaFile): void {
                if (! isset($mediaFile->disk_id, $mediaFile->relative_path)
                    || ! is_string($mediaFile->disk_id)
                    || ! is_string($mediaFile->relative_path)
                ) {
                    throw new RuntimeException('An active media path cannot be backfilled safely.');
                }

                DB::table('media_files')
                    ->where('id', $mediaFile->id)
                    ->update([
                        'active_path_key' => hash('sha256', $mediaFile->disk_id."\0".$mediaFile->relative_path),
                    ]);
            });

        Schema::table('media_files', function (Blueprint $table) {
            $table->unique('active_path_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropUnique(['active_path_key']);
            $table->dropColumn('active_path_key');
            $table->unique(['disk_id', 'relative_path']);
        });
    }
};
