<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique()->nullable();
            $table->string('owner_name')->nullable();
            $table->smallInteger('slot_minutes')->default(30);
            $table->smallInteger('buffer_minutes')->default(0);
            $table->smallInteger('days_ahead')->default(21);
            $table->smallInteger('booking_lead_hours')->default(24);
            $table->string('workdays')->default('1,2,3,4,5');
            $table->string('day_start', 5)->default('09:00');
            $table->string('day_end', 5)->default('17:00');
            $table->text('blackout_dates')->nullable();
            $table->boolean('send_customer_email')->default(true);
            $table->string('caldav_user')->nullable();
            $table->string('caldav_pass')->nullable();
            $table->string('caldav_url')->nullable();
            $table->string('google_client_id')->nullable();
            $table->string('google_client_secret')->nullable();
            $table->text('google_access_token')->nullable();
            $table->string('google_refresh_token')->nullable();
            $table->dateTime('google_token_expires')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_settings');
    }
};
