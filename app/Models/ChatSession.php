<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'openai_thread_id',
        'title',
        'module_filter',
        'user_id',
        'session_type',
        'chat_type',
        'desk365_ticket_id',
        'participant_ids',
    ];

    protected function casts(): array
    {
        return [
            'participant_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_session_favorites')->withTimestamps();
    }

    /**
     * Scope: sessions the user can access (owner or group participant).
     */
    public function scopeAccessibleBy($query, int $userId): void
    {
        $driver = $query->getConnection()->getDriverName();
        $query->where(function ($q) use ($userId, $driver) {
            $q->where('user_id', $userId);
            if ($driver === 'sqlite') {
                $id = (string) $userId;
                $q->orWhere(function ($q2) use ($id) {
                    $q2->whereNotNull('participant_ids')
                        ->where(function ($q3) use ($id) {
                            $q3->where('participant_ids', 'like', '%,'.$id.',%')
                                ->orWhere('participant_ids', 'like', '%,'.$id.']%')
                                ->orWhere('participant_ids', 'like', '%['.$id.',%')
                                ->orWhere('participant_ids', 'like', '%['.$id.']%');
                        });
                });
            } else {
                $q->orWhereJsonContains('participant_ids', $userId);
            }
        });
    }
}
