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
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tmdb_id')->unique();
            $table->string('imdb_id', 32)->nullable()->unique();
            $table->string('title');
            $table->string('original_title')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable()->index();
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('original_language', 16)->nullable();
            $table->unsignedInteger('metadata_version');
            $table->json('metadata_snapshot');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
