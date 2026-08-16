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
        Schema::create('series', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tmdb_id')->unique();
            $table->string('category', 16)->index();
            $table->string('name');
            $table->string('original_name')->nullable();
            $table->date('first_air_date')->nullable();
            $table->unsignedSmallInteger('first_air_year')->nullable()->index();
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('original_language', 16)->nullable();
            $table->json('external_ids');
            $table->unsignedInteger('episode_total')->default(0);
            $table->unsignedInteger('metadata_version');
            $table->json('metadata_snapshot');
            $table->string('home_disk_id', 64)->nullable()->index();
            $table->timestamp('last_episode_finalized_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('series_seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedBigInteger('tmdb_id')->unique();
            $table->unsignedSmallInteger('season_number');
            $table->string('name');
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->date('air_date')->nullable();
            $table->unsignedInteger('episode_count')->default(0);
            $table->unsignedInteger('metadata_version');
            $table->json('metadata_snapshot');
            $table->timestamps();
            $table->unique(['series_id', 'season_number']);
        });

        Schema::create('series_episodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_season_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tmdb_id')->unique();
            $table->unsignedSmallInteger('episode_number');
            $table->string('name');
            $table->text('overview')->nullable();
            $table->date('air_date')->nullable();
            $table->unsignedSmallInteger('runtime_minutes')->nullable();
            $table->unsignedInteger('metadata_version');
            $table->json('metadata_snapshot');
            $table->foreignId('current_media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['series_season_id', 'episode_number']);
        });

        Schema::create('series_upload_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('series_id')->constrained('series')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->json('manifest');
            $table->char('manifest_hash', 64);
            $table->string('disk_id', 64);
            $table->unsignedBigInteger('declared_bytes');
            $table->unsignedBigInteger('confirmed_bytes')->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::table('uploads', function (Blueprint $table): void {
            $table->foreignId('series_episode_id')->nullable()->after('media_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('series_upload_batch_id')->nullable()->after('series_episode_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('batch_position')->nullable()->after('series_upload_batch_id');
            $table->unique(['series_upload_batch_id', 'batch_position']);
        });

        Schema::table('media_files', function (Blueprint $table): void {
            $table->foreignId('series_episode_id')->nullable()->after('media_item_id')->constrained()->restrictOnDelete();
            $table->index(['series_episode_id', 'finalized_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE uploads ADD CONSTRAINT uploads_exactly_one_subject CHECK ((media_item_id IS NOT NULL) <> (series_episode_id IS NOT NULL))');
            DB::statement('ALTER TABLE media_files ADD CONSTRAINT media_files_exactly_one_subject CHECK ((media_item_id IS NOT NULL) <> (series_episode_id IS NOT NULL))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE uploads DROP CHECK uploads_exactly_one_subject');
            DB::statement('ALTER TABLE media_files DROP CHECK media_files_exactly_one_subject');
        }

        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex(['series_episode_id', 'finalized_at']);
            $table->dropConstrainedForeignId('series_episode_id');
        });

        Schema::table('uploads', function (Blueprint $table): void {
            $table->dropUnique(['series_upload_batch_id', 'batch_position']);
            $table->dropConstrainedForeignId('series_upload_batch_id');
            $table->dropConstrainedForeignId('series_episode_id');
            $table->dropColumn('batch_position');
        });

        Schema::dropIfExists('series_upload_batches');
        Schema::dropIfExists('series_episodes');
        Schema::dropIfExists('series_seasons');
        Schema::dropIfExists('series');
    }
};
