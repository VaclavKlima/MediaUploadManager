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
        Schema::create('episode_rename_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_episode_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->foreignId('source_upload_id')->nullable()->constrained('uploads')->restrictOnDelete();
            $table->string('old_custom_name')->nullable();
            $table->string('new_custom_name')->nullable();
            $table->string('disk_id', 64)->nullable();
            $table->string('source_relative_path', 1024)->nullable();
            $table->string('destination_relative_path', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('inode_id')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 128)->nullable();
            $table->text('error_detail')->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['series_episode_id', 'status']);
        });

        Schema::create('series_deletion_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('series_id');
            $table->string('scope_type', 16);
            $table->unsignedBigInteger('scope_id');
            $table->string('series_name');
            $table->string('status', 24)->default('pending');
            $table->json('manifest');
            $table->char('manifest_hash', 64);
            $table->unsignedInteger('file_count');
            $table->unsignedBigInteger('total_size_bytes');
            $table->string('error_code', 128)->nullable();
            $table->text('error_detail')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'status'], 'series_deletion_scope_status_index');
            $table->index(['series_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series_deletion_operations');
        Schema::dropIfExists('episode_rename_operations');
    }
};
