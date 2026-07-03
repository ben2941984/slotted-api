<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'user_id', 'start_dt', 'end_dt', 'name', 'email', 'note', 'ip',
        'status', 'is_google_meet', 'timezone',
        'google_meet_link', 'google_event_id', 'caldav_uid', 'cancel_token',
    ];

    protected $casts = [
        'is_google_meet' => 'boolean',
        'start_dt' => 'datetime',
        'end_dt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
