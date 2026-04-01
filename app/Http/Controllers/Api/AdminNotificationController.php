<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminSendNotificationRequest;
use App\Http\Traits\ApiResponse;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\UserHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AppNotificationService $notifications,
        protected UserHierarchyService $hierarchy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 25);
        $q = $request->input('q');
        $type = $request->input('notification_type');
        $module = $request->input('module');
        $userId = $request->input('user_id');

        $query = InAppNotification::query()->with(['user:id,name,email'])->orderByDesc('created_at');
        $actor = $request->user();
        $visibleIds = $this->hierarchy->visibleUserIdsFor($actor, true);
        if ($visibleIds === []) {
            return $this->sendOk([], [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'total_pages' => 0,
            ]);
        }
        $query->whereIn('user_id', $visibleIds);

        if ($q) {
            $query->where(function ($b) use ($q) {
                $b->where('title', 'like', '%'.$q.'%')
                    ->orWhere('body', 'like', '%'.$q.'%');
            });
        }
        if ($type) {
            $query->where('notification_type', $type);
        }
        if ($module) {
            $query->where('module', $module);
        }
        if ($userId) {
            $query->where('user_id', (int) $userId);
        }

        $total = $query->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get()->map(fn ($n) => $this->formatAdmin($n));

        return $this->sendOk($rows->values()->all(), [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    public function send(AdminSendNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $visibleIds = $this->hierarchy->visibleUserIdsFor($actor, true);
        $sendEmail = (bool) ($data['send_email'] ?? true);
        $type = $data['notification_type'] ?? 'system';
        $module = $data['module'] ?? 'admin';

        foreach ($data['user_ids'] as $uid) {
            if (! in_array((int) $uid, $visibleIds, true)) {
                continue;
            }
            $user = User::find((int) $uid);
            if ($user) {
                $this->notifications->notifyUser(
                    $user,
                    $type,
                    $module,
                    'admin.broadcast',
                    $data['title'],
                    $data['body'] ?? '',
                    ['sent_by' => Auth::id()],
                    $sendEmail
                );
            }
        }

        return $this->sendOk(['success' => true]);
    }

    public function resendEmail(int $id): JsonResponse
    {
        $n = InAppNotification::with('user')->find($id);
        if (! $n) {
            return $this->sendError(404, 'NOT_FOUND', 'Notification not found');
        }
        if (! $n->user || ! $this->hierarchy->canSeeUser(Auth::user(), $n->user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot access this notification');
        }

        $this->notifications->sendEmailForNotification($n, $n->user);

        return $this->sendOk($this->formatAdmin($n->fresh(['user:id,name,email'])));
    }

    public function destroy(int $id): JsonResponse
    {
        $n = InAppNotification::query()->find($id);
        if (! $n) {
            return $this->sendError(404, 'NOT_FOUND', 'Notification not found');
        }
        $target = User::find($n->user_id);
        if (! $target || ! $this->hierarchy->canSeeUser(Auth::user(), $target)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot delete this notification');
        }
        $n->delete();

        return $this->sendOk(['success' => true]);
    }

    private function formatAdmin(InAppNotification $n): array
    {
        $u = $n->user;

        return [
            'id' => $n->id,
            'user_id' => $n->user_id,
            'user' => $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email] : null,
            'notification_type' => $n->notification_type,
            'module' => $n->module,
            'event_key' => $n->event_key,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'email_sent_at' => $n->email_sent_at?->toIso8601String(),
            'email_status' => $n->email_status,
            'email_error' => $n->email_error,
            'created_at' => $n->created_at->toIso8601String(),
            'updated_at' => $n->updated_at->toIso8601String(),
        ];
    }
}
