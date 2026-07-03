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
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('embed_theme', 20)->default('light')->after('blackout_dates');
            $table->string('embed_layout', 20)->default('default')->after('embed_theme');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['embed_theme', 'embed_layout']);
        });
    }
};
