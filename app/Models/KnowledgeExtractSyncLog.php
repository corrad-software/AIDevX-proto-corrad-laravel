<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeExtractSyncLog extends Model
{
    public $timestamps = false;

    protected $table = 'knowledge_extract_sync_logs';

    protected $fillable = [
        'user_id',
        'section',
        'triggered_by',
        'status',
        'message',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
