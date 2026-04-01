<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncedTicket extends Model
{
    protected $fillable = [
        'ticket_number',
        'subject',
        'description',
        'module',
        'status',
        'type',
        'priority',
        'contact_name',
        'company_name',
        'created_time',
        'assigned_agent',
        'desk365_sync_log_id',
    ];

    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(Desk365SyncLog::class, 'desk365_sync_log_id');
    }
}
