<?php

namespace App\Models;

use App\Http\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'ticket_number',
        'subject',
        'description',
        'module',
        'type',
        'priority',
        'status',
        'created_by_user_id',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'closed_by_user_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }
}
