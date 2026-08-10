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
        Schema::create('media_item_reidentifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->foreignId('source_upload_id')->nullable()->constrained('uploads')->restrictOnDelete();
            $table->json('old_metadata_snapshot');
            $table->json('new_metadata_snapshot');
            $table->string('disk_id', 64)->nullable();
            $table->string('source_relative_path', 1024)->nullable();
            $table->string('destination_relative_path', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('inode_id')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('error_code', 128)->nullable();
            $table->text('error_detail')->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['media_item_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_item_reidentifications');
    }
};
