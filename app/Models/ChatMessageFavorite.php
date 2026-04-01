<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageFavorite extends Model
{
    protected $fillable = ['user_id', 'chat_message_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }
}
