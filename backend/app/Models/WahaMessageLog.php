<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WahaMessageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'recipient_user_id',
        'source',
        'phone',
        'message',
        'status',
        'http_status',
        'response_body',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'event_id' => 'integer',
        'recipient_user_id' => 'integer',
        'http_status' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}

