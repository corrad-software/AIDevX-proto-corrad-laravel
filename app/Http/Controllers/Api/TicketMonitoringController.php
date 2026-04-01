<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ChatSession;
use App\Models\Desk365SyncLog;
use App\Models\InternalTicketSyncLog;
use App\Models\KnowledgeDocument;
use App\Models\SupportTicket;
use App\Models\SyncedTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketMonitoringController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->canViewMonitoring($user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot view ticket monitoring');
        }

        return $this->sendOk($this->buildPayload());
    }

    private function canViewMonitoring(User $user): bool
    {
        if (UserLevel::normalize($user->user_level ?? UserLevel::USER) === UserLevel::SUPER_ADMIN) {
            return true;
        }

        $level = UserLevel::normalize($user->user_level ?? UserLevel::USER);
        if (! in_array($level, [
            UserLevel::INTERNAL_ADMIN,
            UserLevel::EXTERNAL_ADMIN,
            UserLevel::AGENT,
            UserLevel::USER,
            UserLevel::SECONDARY_USER,
        ], true)) {
            return false;
        }

        return $user->hasPermission(Permission::KNOWLEDGE_VIEW)
            || $user->hasPermission(Permission::KNOWLEDGE_MANAGE)
            || $user->hasPermission(Permission::TICKETS_VIEW)
            || $user->hasPermission(Permission::TICKETS_CREATE)
            || $user->hasPermission(Permission::TICKETS_RESPOND)
            || $user->hasPermission(Permission::TICKETS_EDIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $internalTotal = SupportTicket::count();
        $internalByStatus = $this->countGrouped(SupportTicket::class, 'status', 'Tiada status');
        $internalByPriority = $this->countGrouped(SupportTicket::class, 'priority', 'Tiada');
        $internalByModule = $this->countGrouped(SupportTicket::class, 'module', 'Tiada modul', 12);

        $openInternal = SupportTicket::query()
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        $unassignedInternal = SupportTicket::query()
            ->whereNull('assigned_to_user_id')
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        $since7 = now()->subDays(7);
        $internalCreatedLast7Days = SupportTicket::query()->where('created_at', '>=', $since7)->count();
        $internalClosedLast7Days = SupportTicket::query()
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $since7)
            ->count();

        $syncedTotal = SyncedTicket::count();
        $syncedByStatus = $this->countGrouped(SyncedTicket::class, 'status', 'Tiada status');
        $syncedByModule = $this->countGrouped(SyncedTicket::class, 'module', 'Tiada modul', 12);
        $syncedByPriority = $this->countGrouped(SyncedTicket::class, 'priority', 'Tiada');

        $lastDesk365Sync = Desk365SyncLog::query()->orderByDesc('created_at')->first();
        $lastInternalSync = InternalTicketSyncLog::query()->orderByDesc('created_at')->first();

        $desk365KbDocs = (int) KnowledgeDocument::query()
            ->where('original_filename', 'like', 'kerisi-tickets-%')
            ->count();
        $internalKbDocs = (int) KnowledgeDocument::query()
            ->where('original_filename', 'like', 'kerisi-afsa-tickets-%')
            ->count();
        $desk365KbUploaded = (int) KnowledgeDocument::query()
            ->where('original_filename', 'like', 'kerisi-tickets-%')
            ->where('status', 'uploaded')
            ->count();
        $internalKbUploaded = (int) KnowledgeDocument::query()
            ->where('original_filename', 'like', 'kerisi-afsa-tickets-%')
            ->where('status', 'uploaded')
            ->count();

        return [
            'internal' => [
                'total' => $internalTotal,
                'open' => $openInternal,
                'unassigned' => $unassignedInternal,
                'by_status' => $internalByStatus,
                'by_priority' => $internalByPriority,
                'by_module' => $internalByModule,
                'open_by_assignee' => $this->openInternalTicketsByAssignee(),
                'created_last_7_days' => $internalCreatedLast7Days,
                'closed_last_7_days' => $internalClosedLast7Days,
            ],
            'desk365_synced' => [
                'total' => $syncedTotal,
                'by_status' => $syncedByStatus,
                'by_module' => $syncedByModule,
                'by_priority' => $syncedByPriority,
                'open_by_agent' => $this->openDesk365TicketsByAgent(),
            ],
            'chat_activity' => [
                'sessions_by_user' => $this->chatSessionsTopUsers(),
            ],
            'ai_knowledge' => [
                'desk365_document_count' => $desk365KbDocs,
                'desk365_uploaded_count' => $desk365KbUploaded,
                'internal_document_count' => $internalKbDocs,
                'internal_uploaded_count' => $internalKbUploaded,
            ],
            'last_sync' => [
                'desk365' => $lastDesk365Sync ? [
                    'created_at' => $lastDesk365Sync->created_at,
                    'status' => $lastDesk365Sync->status,
                    'total_tickets' => $lastDesk365Sync->total_tickets,
                    'uploaded' => $lastDesk365Sync->uploaded,
                    'failed' => $lastDesk365Sync->failed,
                    'message' => $lastDesk365Sync->message,
                ] : null,
                'internal' => $lastInternalSync ? [
                    'created_at' => $lastInternalSync->created_at,
                    'status' => $lastInternalSync->status,
                    'total_tickets' => $lastInternalSync->total_tickets,
                    'uploaded' => $lastInternalSync->uploaded,
                    'failed' => $lastInternalSync->failed,
                    'message' => $lastInternalSync->message,
                ] : null,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array{label: string, count: int}> */
    private function openInternalTicketsByAssignee(): array
    {
        $closed = ['closed', 'resolved', 'complete', 'completed'];
        $assigneeLabel = "COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(u.name), ''), '—')";

        return SupportTicket::query()
            ->from('support_tickets')
            ->leftJoin('users as u', 'u.id', '=', 'support_tickets.assigned_to_user_id')
            ->where(function ($q) use ($closed) {
                $q->whereNull('support_tickets.status')
                    ->orWhereNotIn(DB::raw('LOWER(TRIM(COALESCE(support_tickets.status, \'\')))'), $closed);
            })
            ->selectRaw("{$assigneeLabel} as label, COUNT(*) as cnt")
            ->groupByRaw($assigneeLabel)
            ->orderByDesc('cnt')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'count' => (int) $r->cnt])
            ->values()
            ->all();
    }

    /** Open / non-terminal Desk365 synced tickets grouped by assigned_agent. */
    private function openDesk365TicketsByAgent(): array
    {
        $closed = ['closed', 'resolved', 'complete', 'completed'];
        $agentLabel = "COALESCE(NULLIF(TRIM(synced_tickets.assigned_agent), ''), '—')";

        return SyncedTicket::query()
            ->where(function ($q) use ($closed) {
                $q->whereNull('synced_tickets.status')
                    ->orWhereNotIn(DB::raw('LOWER(TRIM(COALESCE(synced_tickets.status, \'\')))'), $closed);
            })
            ->selectRaw("{$agentLabel} as label, COUNT(*) as cnt")
            ->groupByRaw($agentLabel)
            ->orderByDesc('cnt')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'count' => (int) $r->cnt])
            ->values()
            ->all();
    }

    /** Top chat session owners (SELAR / AINA activity). */
    private function chatSessionsTopUsers(): array
    {
        $rows = ChatSession::query()
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->orderByDesc('cnt')
            ->limit(12)
            ->get();

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($users) {
                $u = $users->get($row->user_id);
                $label = '—';
                if ($u) {
                    $email = trim((string) $u->email);
                    $name = trim((string) $u->name);
                    $label = $email !== '' ? $email : ($name !== '' ? $name : 'User #'.$u->id);
                }

                return ['label' => $label, 'count' => (int) $row->cnt];
            })
            ->values()
            ->all();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<array{label: string, count: int}>
     */
    private function countGrouped(string $modelClass, string $column, string $emptyLabel, ?int $limit = null): array
    {
        $label = fn ($v) => trim((string) $v) === '' ? $emptyLabel : (string) $v;

        $counts = $modelClass::query()
            ->get()
            ->groupBy(fn (Model $m) => $label($m->getAttribute($column)))
            ->map->count()
            ->sortDesc();

        if ($limit !== null) {
            $counts = $counts->take($limit);
        }

        return $counts
            ->map(fn (int $c, string $k) => ['label' => $k, 'count' => $c])
            ->values()
            ->all();
    }
}
