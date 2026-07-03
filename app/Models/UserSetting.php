<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id', 'slug', 'owner_name',
        'slot_minutes', 'buffer_minutes', 'days_ahead', 'booking_lead_hours',
        'workdays', 'day_start', 'day_end', 'blackout_dates', 'send_customer_email',
        'caldav_user', 'caldav_pass', 'caldav_url',
        'google_client_id', 'google_client_secret',
        'google_access_token', 'google_refresh_token', 'google_token_expires',
        'embed_theme', 'embed_layout',
    ];

    protected $hidden = ['caldav_pass', 'google_access_token', 'google_refresh_token'];

    protected $casts = [
        'send_customer_email' => 'boolean',
        'google_token_expires' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function googleConnected(): bool
    {
        return !empty($this->google_access_token) && !empty($this->google_client_id);
    }
}
