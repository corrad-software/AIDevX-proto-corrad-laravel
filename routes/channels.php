<?php

use App\Models\ChatSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.session.{sessionId}', function ($user, $sessionId) {
    return ChatSession::where('id', (int) $sessionId)->accessibleBy($user->id)->exists();
});
