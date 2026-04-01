<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarkNotificationsReadRequest;
use App\Http\Traits\ApiResponse;
use App\Models\InAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);
        $unreadOnly = filter_var($request->input('unread_only', false), FILTER_VALIDATE_BOOLEAN);

        $query = InAppNotification::query()->where('user_id', $userId)->orderByDesc('created_at');
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $total = $query->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get()->map(fn ($n) => $this->format($n));

        return $this->sendOk($rows->values()->all(), [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $count = InAppNotification::query()
            ->where('user_id', Auth::id())
            ->whereNull('read_at')
            ->count();

        return $this->sendOk(['count' => $count]);
    }

    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $ids = $request->validated()['ids'];
        InAppNotification::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->sendOk(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        InAppNotification::query()
            ->where('user_id', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->sendOk(['success' => true]);
    }

    private function format(InAppNotification $n): array
    {
        return [
            'id' => $n->id,
            'user_id' => $n->user_id,
            'notification_type' => $n->notification_type,
            'module' => $n->module,
            'event_key' => $n->event_key,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'email_sent_at' => $n->email_sent_at?->toIso8601String(),
            'email_status' => $n->email_status,
            'created_at' => $n->created_at->toIso8601String(),
            'updated_at' => $n->updated_at->toIso8601String(),
        ];
    }
}
