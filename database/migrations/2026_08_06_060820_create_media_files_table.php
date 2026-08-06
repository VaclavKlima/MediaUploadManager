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
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_upload_id')->unique()->constrained('uploads')->restrictOnDelete();
            $table->string('disk_id', 64);
            $table->string('relative_path', 1024);
            $table->unsignedBigInteger('size_bytes');
            $table->string('container', 32);
            $table->unsignedBigInteger('duration_milliseconds');
            $table->json('video_metadata');
            $table->json('audio_metadata');
            $table->json('probe_snapshot');
            $table->timestamp('finalized_at');
            $table->foreignId('replaced_by_media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['disk_id', 'relative_path']);
            $table->index(['media_item_id', 'finalized_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
