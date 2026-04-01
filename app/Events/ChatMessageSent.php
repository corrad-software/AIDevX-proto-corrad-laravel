<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.session.'.$this->message->chat_session_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $m = $this->message;
        $m->loadMissing(['mentionToUser:id,name', 'replyToUser:id,name']);
        $msg = [
            'id' => $m->id,
            'chatSessionId' => $m->chat_session_id,
            'role' => $m->role,
            'content' => $m->content,
            'citations' => $m->citations ?? [],
            'replyToMessageId' => $m->reply_to_message_id,
            'replyToUserId' => $m->reply_to_user_id,
            'mentionToUserId' => $m->mention_to_user_id,
            'replyToUser' => $m->replyToUser ? ['id' => $m->replyToUser->id, 'name' => $m->replyToUser->name] : null,
            'mentionToUser' => $m->mentionToUser ? ['id' => $m->mentionToUser->id, 'name' => $m->mentionToUser->name] : null,
            'createdAt' => $m->created_at?->format('c'),
        ];

        return ['message' => $msg];
    }
}
