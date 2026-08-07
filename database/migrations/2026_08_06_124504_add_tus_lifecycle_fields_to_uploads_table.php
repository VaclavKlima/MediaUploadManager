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
        Schema::table('uploads', function (Blueprint $table) {
            $table->timestamp('tus_creation_claimed_at')->nullable()->after('tus_resource_id');
            $table->timestamp('tus_created_at')->nullable()->after('tus_creation_claimed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn(['tus_creation_claimed_at', 'tus_created_at']);
        });
    }
};
