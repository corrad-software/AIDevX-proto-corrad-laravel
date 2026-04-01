<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_session_id',
        'role',
        'content',
        'citations',
        'reply_to_message_id',
        'reply_to_user_id',
        'mention_to_user_id',
    ];

    protected function casts(): array
    {
        return [
            'citations' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function replyToMessage(): ?BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_message_id');
    }

    public function replyToUser(): ?BelongsTo
    {
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }

    public function mentionToUser(): ?BelongsTo
    {
        return $this->belongsTo(User::class, 'mention_to_user_id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_message_favorites')->withTimestamps();
    }
}
