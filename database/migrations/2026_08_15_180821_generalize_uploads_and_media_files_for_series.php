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
        Schema::table('uploads', function (Blueprint $table): void {
            $table->string('root_kind', 16)->default('movies')->after('disk_id');
            $table->unsignedBigInteger('media_item_id')->nullable()->change();
            $table->index(['root_kind', 'disk_id', 'status']);
        });

        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('root_kind', 16)->default('movies')->after('disk_id');
            $table->unsignedBigInteger('media_item_id')->nullable()->change();
            $table->index(['root_kind', 'disk_id', 'finalized_at']);
        });

        DB::table('media_files')->select(['id', 'disk_id', 'relative_path'])->whereNotNull('active_path_key')->orderBy('id')->eachById(
            function (object $mediaFile): void {
                if (! is_string($mediaFile->disk_id ?? null) || ! is_string($mediaFile->relative_path ?? null)) {
                    throw new RuntimeException('Legacy media-file paths must be strings.');
                }

                DB::table('media_files')->where('id', $mediaFile->id)->update([
                    'active_path_key' => hash('sha256', 'movies'."\0".$mediaFile->disk_id."\0".$mediaFile->relative_path),
                ]);
            },
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('media_files')->select(['id', 'disk_id', 'relative_path'])->whereNotNull('active_path_key')->orderBy('id')->eachById(
            function (object $mediaFile): void {
                if (! is_string($mediaFile->disk_id ?? null) || ! is_string($mediaFile->relative_path ?? null)) {
                    throw new RuntimeException('Media-file paths must be strings.');
                }

                DB::table('media_files')->where('id', $mediaFile->id)->update([
                    'active_path_key' => hash('sha256', $mediaFile->disk_id."\0".$mediaFile->relative_path),
                ]);
            },
        );

        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex(['root_kind', 'disk_id', 'finalized_at']);
            $table->dropColumn('root_kind');
            $table->unsignedBigInteger('media_item_id')->nullable(false)->change();
        });

        Schema::table('uploads', function (Blueprint $table): void {
            $table->dropIndex(['root_kind', 'disk_id', 'status']);
            $table->dropColumn('root_kind');
            $table->unsignedBigInteger('media_item_id')->nullable(false)->change();
        });
    }
};
