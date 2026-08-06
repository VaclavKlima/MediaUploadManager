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
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_item_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('disk_id', 64);
            $table->string('target_relative_path', 1024);
            $table->string('staging_relative_path', 1024);
            $table->string('original_filename');
            $table->string('extension', 16);
            $table->unsignedBigInteger('declared_size');
            $table->unsignedBigInteger('confirmed_offset')->default(0);
            $table->unsignedBigInteger('last_modified_milliseconds')->nullable();
            $table->char('fingerprint_first_sha256', 64);
            $table->char('fingerprint_last_sha256', 64);
            $table->string('tus_resource_id')->nullable()->unique();
            $table->char('token_hash', 64)->nullable();
            $table->json('token_abilities')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->string('error_detail', 500)->nullable();
            $table->unsignedBigInteger('replaces_media_file_id')->nullable()->index();
            $table->timestamp('replacement_confirmed_at')->nullable();
            $table->timestamp('uploading_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
