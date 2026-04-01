<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Desk365SyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'triggered_by',
        'total_tickets',
        'modules_synced',
        'uploaded',
        'failed',
        'status',
        'message',
        'uploaded_modules',
        'uploaded_ticket_numbers',
        'uploaded_module_counts',
        'uploaded_ticket_details',
    ];

    protected function casts(): array
    {
        return [
            'total_tickets' => 'integer',
            'modules_synced' => 'integer',
            'uploaded' => 'integer',
            'failed' => 'integer',
            'uploaded_modules' => 'array',
            'uploaded_ticket_numbers' => 'array',
            'uploaded_module_counts' => 'array',
            'uploaded_ticket_details' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
