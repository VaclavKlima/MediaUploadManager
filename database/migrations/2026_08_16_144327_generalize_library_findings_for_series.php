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
        Schema::table('library_findings', function (Blueprint $table) {
            $table->index('library_scan_id', 'library_findings_scan_index');
        });

        Schema::table('library_findings', function (Blueprint $table) {
            $table->string('root_kind', 16)->default('movies')->after('library_scan_id');
            $table->foreignId('series_episode_id')
                ->nullable()
                ->after('media_item_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('series_category', 16)->nullable()->after('imdb_id');
            $table->unsignedSmallInteger('season_number')->nullable()->after('series_category');
            $table->unsignedSmallInteger('episode_number')->nullable()->after('season_number');
            $table->dropUnique(['library_scan_id', 'path_key']);
            $table->unique(['library_scan_id', 'root_kind', 'path_key'], 'library_findings_scan_root_path_unique');
            $table->index(['root_kind', 'disk_id', 'path_key'], 'library_findings_root_disk_path_index');
            $table->index(['root_kind', 'status'], 'library_findings_root_status_index');
            $table->index(
                ['root_kind', 'tmdb_id', 'season_number', 'episode_number'],
                'library_findings_series_identity_index',
            );
        });

        DB::table('library_findings')->select(['id', 'disk_id', 'relative_path'])->orderBy('id')->eachById(
            function (object $finding): void {
                if (! is_string($finding->disk_id ?? null) || ! is_string($finding->relative_path ?? null)) {
                    throw new RuntimeException('Legacy library finding paths must be strings.');
                }

                DB::table('library_findings')->where('id', $finding->id)->update([
                    'path_key' => hash('sha256', 'movies'."\0".$finding->disk_id."\0".$finding->relative_path),
                ]);
            },
        );

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE library_findings ADD CONSTRAINT library_findings_root_kind_valid CHECK (root_kind IN ('movies', 'series'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE library_findings DROP CHECK library_findings_root_kind_valid');
        }

        DB::table('library_findings')->select(['id', 'disk_id', 'relative_path'])->orderBy('id')->eachById(
            function (object $finding): void {
                if (! is_string($finding->disk_id ?? null) || ! is_string($finding->relative_path ?? null)) {
                    throw new RuntimeException('Library finding paths must be strings.');
                }

                DB::table('library_findings')->where('id', $finding->id)->update([
                    'path_key' => hash('sha256', $finding->disk_id."\0".$finding->relative_path),
                ]);
            },
        );

        Schema::table('library_findings', function (Blueprint $table) {
            $table->dropIndex('library_findings_series_identity_index');
            $table->dropIndex('library_findings_root_status_index');
            $table->dropIndex('library_findings_root_disk_path_index');
            $table->dropUnique('library_findings_scan_root_path_unique');
            $table->unique(['library_scan_id', 'path_key']);
            $table->dropConstrainedForeignId('series_episode_id');
            $table->dropColumn(['root_kind', 'series_category', 'season_number', 'episode_number']);
            $table->dropIndex('library_findings_scan_index');
        });
    }
};
