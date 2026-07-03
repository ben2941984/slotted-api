<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('start_dt');
            $table->dateTime('end_dt');
            $table->string('name');
            $table->string('email');
            $table->string('note')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('status', 20)->default('confirmed');
            $table->boolean('is_google_meet')->default(false);
            $table->string('timezone')->nullable();
            $table->string('google_meet_link')->nullable();
            $table->string('google_event_id')->nullable();
            $table->string('caldav_uid')->nullable();
            $table->string('cancel_token', 64)->nullable()->index();
            $table->timestamps();

            $table->index('start_dt');
            $table->index('status');
        });
    }

    public function down(): void {
        Schema::dropIfExists('bookings');
    }
};
