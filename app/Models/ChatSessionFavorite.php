<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSessionFavorite extends Model
{
    protected $fillable = ['user_id', 'chat_session_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }
}
