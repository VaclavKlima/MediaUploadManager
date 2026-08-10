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
        Schema::create('folder_cleanups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('disk_id', 64);
            $table->string('relative_folder', 1024);
            $table->string('status', 32)->index();
            $table->json('manifest');
            $table->string('manifest_hash', 64);
            $table->unsignedInteger('file_count');
            $table->unsignedBigInteger('total_size_bytes');
            $table->text('error_detail')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folder_cleanups');
    }
};
