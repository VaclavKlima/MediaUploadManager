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
        Schema::create('library_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk_id', 64);
            $table->string('relative_path', 1024);
            $table->char('path_key', 64);
            $table->string('source_folder', 1024);
            $table->string('source_filename', 255);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('inode_id')->nullable();
            $table->string('kind', 32)->default('discovered');
            $table->string('status', 32)->index();
            $table->string('identity_source', 32)->nullable();
            $table->json('identity_snapshot')->nullable();
            $table->unsignedBigInteger('tmdb_id')->nullable()->index();
            $table->string('imdb_id', 16)->nullable()->index();
            $table->string('destination_relative_path', 1024)->nullable();
            $table->json('operation_claim')->nullable();
            $table->text('error_detail')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['library_scan_id', 'path_key']);
            $table->index('path_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_findings');
    }
};
