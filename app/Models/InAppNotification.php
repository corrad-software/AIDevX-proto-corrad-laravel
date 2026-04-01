<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InAppNotification extends Model
{
    protected $table = 'in_app_notifications';

    protected $fillable = [
        'user_id',
        'notification_type',
        'module',
        'event_key',
        'title',
        'body',
        'data',
        'read_at',
        'email_sent_at',
        'email_status',
        'email_error',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
