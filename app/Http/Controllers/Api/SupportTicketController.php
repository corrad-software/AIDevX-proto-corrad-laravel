<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentReplySuggestionRequest;
use App\Http\Requests\AssignSupportTicketRequest;
use App\Http\Requests\ReplySupportTicketRequest;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Http\Requests\UpdateSupportTicketRequest;
use App\Http\Traits\ApiResponse;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\SupportTicketAiService;
use App\Services\UserHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected UserHierarchyService $hierarchy,
        protected AppNotificationService $notifications,
        protected SupportTicketAiService $ticketAi,
    ) {}

    private function actorLevel(User $user): string
    {
        return UserLevel::normalize($user->user_level ?? UserLevel::USER);
    }

    /** Whether the actor may assign a ticket to an agent (Level 0–3 staff only; not end users). */
    private function canAssignTicket(User $user): bool
    {
        return in_array($this->actorLevel($user), [
            UserLevel::SUPER_ADMIN,
            UserLevel::INTERNAL_ADMIN,
            UserLevel::EXTERNAL_ADMIN,
            UserLevel::AGENT,
        ], true);
    }

    private function canRespond(User $user): bool
    {
        return in_array($this->actorLevel($user), [UserLevel::INTERNAL_ADMIN, UserLevel::EXTERNAL_ADMIN, UserLevel::AGENT, UserLevel::SUPER_ADMIN], true);
    }

    private function visibleUserIds(User $actor): array
    {
        return $this->hierarchy->visibleUserIdsFor($actor, true);
    }

    private function baseQueryForActor(User $actor)
    {
        $level = $this->actorLevel($actor);
        $query = SupportTicket::query()
            ->with(['requestor:id,name,email,user_level', 'assignee:id,name,email,user_level'])
            ->orderByDesc('created_at');

        if ($level === UserLevel::SUPER_ADMIN) {
            return $query;
        }
        if ($level === UserLevel::INTERNAL_ADMIN) {
            // Level 1 handles all incoming tickets for first-line review.
            return $query;
        }

        $visibleIds = $this->visibleUserIds($actor);
        if (UserLevel::isEndUserTier($level)) {
            return $query->where('created_by_user_id', $actor->id);
        }
        if ($level === UserLevel::AGENT) {
            // Include tickets this agent assigned to someone else (reassign handoff) so GET detail
            // still works after assigned_to_user_id changes away from the actor.
            return $query->where(function ($q) use ($actor) {
                $q->where('assigned_to_user_id', $actor->id)
                    ->orWhere('created_by_user_id', $actor->id)
                    ->orWhere('assigned_by_user_id', $actor->id);
            });
        }

        if ($visibleIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($visibleIds) {
            $q->whereIn('created_by_user_id', $visibleIds)
                ->orWhereIn('assigned_to_user_id', $visibleIds);
        });
    }

    private function formatTicket(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'customer_name' => $ticket->customer_name,
            'system_name' => $ticket->system_name,
            'module' => $ticket->module,
            'type' => $ticket->type,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'ai_assistance_enabled' => (bool) $ticket->ai_assistance_enabled,
            'ai_awaiting_satisfaction' => (bool) $ticket->ai_awaiting_satisfaction,
            'created_by_user_id' => $ticket->created_by_user_id,
            'assigned_to_user_id' => $ticket->assigned_to_user_id,
            'assigned_by_user_id' => $ticket->assigned_by_user_id,
            'assigned_at' => $ticket->assigned_at?->toIso8601String(),
            'closed_by_user_id' => $ticket->closed_by_user_id,
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'requestor' => $ticket->requestor ? [
                'id' => $ticket->requestor->id,
                'name' => $ticket->requestor->name,
                'email' => $ticket->requestor->email,
                'user_level' => UserLevel::normalize($ticket->requestor->user_level ?? UserLevel::USER),
            ] : null,
            'assignee' => $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'name' => $ticket->assignee->name,
                'email' => $ticket->assignee->email,
                'user_level' => UserLevel::normalize($ticket->assignee->user_level ?? UserLevel::USER),
            ] : null,
        ];
    }

    private function nextTicketNumber(): string
    {
        $lastId = (int) (SupportTicket::query()->max('id') ?? 0) + 1;

        return 'TKT-'.now()->format('Ymd').'-'.str_pad((string) $lastId, 6, '0', STR_PAD_LEFT);
    }

    /** Plain excerpt for in-app / email notification (markdown reply → readable text). */
    private function plainTextExcerptForTicketNotify(string $message): string
    {
        $t = $message;
        $t = preg_replace('/```[\s\S]*?```/', ' ', $t) ?? $t;
        $t = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $t) ?? $t;
        $t = preg_replace('/^#{1,6}\s+/m', '', $t) ?? $t;
        $t = preg_replace('/\*\*([^*]+)\*\*/', '$1', $t) ?? $t;
        $t = preg_replace('/\*([^*]+)\*/', '$1', $t) ?? $t;
        $t = preg_replace('/`([^`]+)`/', '$1', $t) ?? $t;
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        return Str::limit(trim($t), 600, '…');
    }

    /**
     * Users that may be @mentioned on this ticket for the current actor (requestor, assignee, assignable agents).
     *
     * @return list<int>
     */
    private function allowedMentionUserIdsForTicket(User $actor, SupportTicket $ticket): array
    {
        $ids = [];
        if ($ticket->created_by_user_id) {
            $ids[] = (int) $ticket->created_by_user_id;
        }
        if ($ticket->assigned_to_user_id) {
            $ids[] = (int) $ticket->assigned_to_user_id;
        }
        $agentIds = $this->hierarchy->assignableAgentIdsForTicket($actor);

        return array_values(array_unique(array_merge($ids, $agentIds)));
    }

    private function canMoveTo(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        $allowed = [
            'new' => ['assigned', 'closed'],
            'assigned' => ['in_progress', 'pending_requestor', 'resolved', 'closed'],
            'in_progress' => ['pending_requestor', 'resolved', 'closed'],
            'pending_requestor' => ['in_progress', 'resolved', 'closed'],
            'resolved' => ['closed', 'in_progress'],
            'closed' => [],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(max(1, (int) $request->input('limit', 20)), 100);
        $q = trim((string) $request->input('q', ''));
        $status = $request->input('status');

        $query = $this->baseQueryForActor($actor);
        if ($q !== '') {
            $qLower = mb_strtolower($q);
            $query->where(function ($b) use ($qLower) {
                $b->whereRaw('LOWER(ticket_number) LIKE ?', ['%'.$qLower.'%'])
                    ->orWhereRaw('LOWER(subject) LIKE ?', ['%'.$qLower.'%'])
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$qLower.'%'])
                    // Single-quoted empty string: MySQL treats "" as identifier, not string — caused HTTP 500 on search.
                    ->orWhereRaw('LOWER(COALESCE(customer_name, \'\')) LIKE ?', ['%'.$qLower.'%'])
                    ->orWhereRaw('LOWER(COALESCE(system_name, \'\')) LIKE ?', ['%'.$qLower.'%']);
            });
        }
        if ($status) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->sendOk($rows->map(fn ($t) => $this->formatTicket($t))->values()->all(), [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $creatorLevel = $this->actorLevel($actor);
        if (! in_array($creatorLevel, [UserLevel::USER, UserLevel::SECONDARY_USER, UserLevel::AGENT], true)) {
            return $this->sendError(403, 'FORBIDDEN', 'Only Level 3 (agent) or Level 4–5 (end user) can create tickets here');
        }

        $data = $request->validated();
        $ticket = DB::transaction(function () use ($actor, $data) {
            $customer = $actor->customers()->select(['customers.customer_name', 'customers.system_name'])->first();
            $ticket = SupportTicket::create([
                'ticket_number' => $this->nextTicketNumber(),
                'subject' => $data['subject'],
                'description' => $data['description'],
                'customer_name' => $data['customer_name'] ?? $customer?->customer_name ?? $actor->customer_code,
                'system_name' => $data['system_name'] ?? $customer?->system_name ?? null,
                'module' => $data['module'] ?? null,
                'type' => $data['type'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'new',
                'ai_assistance_enabled' => array_key_exists('ai_assistance_enabled', $data)
                    ? (bool) $data['ai_assistance_enabled']
                    : true,
                'ai_awaiting_satisfaction' => false,
                'created_by_user_id' => $actor->id,
            ]);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'message' => $data['description'],
                'is_internal' => false,
            ]);

            return $ticket;
        });

        $ticket->refresh();
        $ticket->load('messages');
        if ($ticket->ai_assistance_enabled) {
            try {
                $this->ticketAi->afterTicketCreated($ticket);
            } catch (\Throwable $e) {
                Log::error('ticket_aina_after_create_failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $reviewers = User::query()
            ->where('is_active', true)
            ->whereIn('user_level', [UserLevel::INTERNAL_ADMIN, UserLevel::EXTERNAL_ADMIN, UserLevel::SUPER_ADMIN])
            ->pluck('id')
            ->all();
        $this->notifications->notifyMany(
            $reviewers,
            'system',
            'ticket',
            'ticket.new_internal',
            'New ticket '.$ticket->ticket_number,
            $ticket->subject,
            ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
            true
        );

        $ticket->load(['requestor', 'assignee']);

        return $this->sendCreated($this->formatTicket($ticket));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        $ticket->load(['messages.user:id,name,email,user_level']);
        $formattedMessages = $ticket->messages->map(fn ($m) => [
            'id' => $m->id,
            'ticket_id' => $m->support_ticket_id,
            'user_id' => $m->user_id,
            'message' => $m->message,
            'is_internal' => (bool) $m->is_internal,
            'is_ai_message' => (bool) $m->is_ai_message,
            'created_at' => $m->created_at?->toIso8601String(),
            'user' => $m->user ? [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'user_level' => UserLevel::normalize($m->user->user_level ?? UserLevel::USER),
            ] : null,
        ])->values()->all();

        return $this->sendOk([
            ...$this->formatTicket($ticket),
            'messages' => $formattedMessages,
        ]);
    }

    public function update(UpdateSupportTicketRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        $level = $this->actorLevel($actor);
        $data = $request->validated();

        if (UserLevel::isEndUserTier($level)) {
            if ((int) $ticket->created_by_user_id !== (int) $actor->id) {
                return $this->sendError(403, 'FORBIDDEN', 'You can update your own ticket only');
            }
            if ($ticket->status !== 'new') {
                return $this->sendError(409, 'TICKET_LOCKED', 'Ticket cannot be edited after it has been processed');
            }
            unset($data['status']);
        } else {
            unset($data['ai_assistance_enabled']);
        }

        // Only internal + super admin may change ticket content; agents/L2 use replies only.
        if (in_array($level, [UserLevel::AGENT, UserLevel::EXTERNAL_ADMIN], true)) {
            foreach (['subject', 'description', 'customer_name', 'system_name', 'module', 'type', 'priority'] as $key) {
                unset($data[$key]);
            }
        }
        if (isset($data['status']) && ! $this->canMoveTo((string) $ticket->status, (string) $data['status'])) {
            return $this->sendError(409, 'INVALID_STATUS_TRANSITION', 'Invalid ticket status transition');
        }

        // Sync closure fields when staff PATCH status (same rules as reply/close).
        if (! UserLevel::isEndUserTier($level) && isset($data['status'])) {
            $next = (string) $data['status'];
            if ($next === 'closed') {
                $data['closed_by_user_id'] = $actor->id;
                $data['closed_at'] = now();
            } elseif ($next === 'resolved') {
                $data['closed_by_user_id'] = null;
                $data['closed_at'] = null;
            } elseif (in_array($ticket->status, ['closed', 'resolved'], true)) {
                $data['closed_by_user_id'] = null;
                $data['closed_at'] = null;
            }
        }

        $ticket->update($data);
        $ticket->refresh();
        $ticket->load(['requestor', 'assignee']);

        return $this->sendOk($this->formatTicket($ticket));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        if (! UserLevel::isEndUserTier($this->actorLevel($actor)) || (int) $ticket->created_by_user_id !== (int) $actor->id) {
            return $this->sendError(403, 'FORBIDDEN', 'Only requestor can delete own ticket');
        }
        if ($ticket->status !== 'new') {
            return $this->sendError(409, 'TICKET_LOCKED', 'Only new ticket can be deleted');
        }

        $ticket->delete();

        return $this->sendOk(['success' => true]);
    }

    public function assign(AssignSupportTicketRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        if (! $this->canAssignTicket($actor)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot assign this ticket');
        }

        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        $data = $request->validated();
        $assignee = User::find((int) $data['assigned_to_user_id']);
        if (! $assignee || UserLevel::normalize($assignee->user_level ?? UserLevel::USER) !== UserLevel::AGENT) {
            return $this->sendError(422, 'VALIDATION_ERROR', 'Assignee must be an agent');
        }
        $allowedAgentIds = $this->hierarchy->assignableAgentIdsForTicket($actor);
        if (! in_array((int) $assignee->id, $allowedAgentIds, true)) {
            return $this->sendError(403, 'FORBIDDEN', 'Assignee outside your hierarchy scope');
        }

        $previousAssigneeId = $ticket->assigned_to_user_id ? (int) $ticket->assigned_to_user_id : null;
        $previousAssignee = $previousAssigneeId ? User::query()->where('id', $previousAssigneeId)->first() : null;

        DB::transaction(function () use ($ticket, $assignee, $actor, $data, $previousAssignee) {
            $ticket->update([
                'assigned_to_user_id' => $assignee->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'status' => 'assigned',
            ]);

            if ($previousAssignee && (int) $previousAssignee->id !== (int) $assignee->id) {
                $conversationLine = sprintf(
                    '**Alihan tugas:** Tiket ditugaskan daripada **%s** kepada **%s** oleh **%s**.',
                    $previousAssignee->name,
                    $assignee->name,
                    $actor->name
                );
            } else {
                $conversationLine = sprintf(
                    '**Penugasan:** Tiket ditugaskan kepada **%s** oleh **%s**.',
                    $assignee->name,
                    $actor->name
                );
            }

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'message' => $conversationLine,
                'is_internal' => false,
            ]);

            if (! empty($data['note'])) {
                SupportTicketMessage::create([
                    'support_ticket_id' => $ticket->id,
                    'user_id' => $actor->id,
                    'message' => '[NOTA DALAMAN TUGASAN] '.$data['note'],
                    'is_internal' => true,
                ]);
            }
        });

        $ticket->refresh();
        $ticket->load(['requestor', 'assignee']);

        try {
            $this->notifications->notifyUser(
                $assignee,
                'system',
                'ticket',
                'ticket.assigned',
                'Tiket '.$ticket->ticket_number.' ditugaskan kepada anda',
                $ticket->subject,
                ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
                true
            );
        } catch (\Throwable $e) {
            Log::warning('ticket_assign_notify_failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        $ticket->loadMissing('requestor');
        if ($ticket->requestor && (int) $ticket->requestor->id !== (int) $assignee->id) {
            try {
                $this->notifications->notifyUser(
                    $ticket->requestor,
                    'system',
                    'ticket',
                    'ticket.reassigned',
                    'Tiket '.$ticket->ticket_number.' — ejen baharu',
                    sprintf('%s kini menangani tiket anda: %s', $assignee->name, $ticket->subject),
                    ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'assignee_user_id' => $assignee->id],
                    true
                );
            } catch (\Throwable $e) {
                Log::warning('ticket_reassign_requestor_notify_failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->sendOk($this->formatTicket($ticket));
    }

    public function reply(ReplySupportTicketRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        $isRequestor = (int) $ticket->created_by_user_id === (int) $actor->id;
        if (! $isRequestor && ! $this->canRespond($actor)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot reply this ticket');
        }

        $data = $request->validated();
        $isInternal = (bool) ($data['is_internal'] ?? false);
        if ($isRequestor) {
            $isInternal = false;
        }

        $nextStatus = $data['status'] ?? null;
        if (! $nextStatus) {
            $nextStatus = $isRequestor ? 'pending_requestor' : 'in_progress';
        }
        if (! $this->canMoveTo((string) $ticket->status, (string) $nextStatus)) {
            return $this->sendError(409, 'INVALID_STATUS_TRANSITION', 'Invalid ticket status transition');
        }

        $mentionRaw = $data['mentioned_user_ids'] ?? [];
        $mentionIds = is_array($mentionRaw)
            ? array_values(array_unique(array_map('intval', $mentionRaw)))
            : [];
        if ($mentionIds !== []) {
            $allowed = $this->allowedMentionUserIdsForTicket($actor, $ticket);
            foreach ($mentionIds as $uid) {
                if (! in_array($uid, $allowed, true)) {
                    return $this->sendError(422, 'VALIDATION_ERROR', 'Mentioned user is not allowed for this ticket');
                }
            }
        }

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'message' => $data['message'],
            'is_internal' => $isInternal,
        ]);

        $updates = ['status' => $nextStatus];
        if ($nextStatus === 'closed') {
            $updates['closed_by_user_id'] = $actor->id;
            $updates['closed_at'] = now();
        }
        if ($nextStatus === 'resolved') {
            $updates['closed_by_user_id'] = null;
            $updates['closed_at'] = null;
        }
        $ticket->update($updates);

        // Notify pemohon: balasan staf sahaja; nota dalaman tidak dihantar kepada requestor.
        if (! $isRequestor && ! $isInternal) {
            $ticket->loadMissing('requestor');
            if ($ticket->requestor) {
                try {
                    $this->notifications->notifyUser(
                        $ticket->requestor,
                        'system',
                        'ticket',
                        'ticket.reply',
                        'Balasan tiket '.$ticket->ticket_number,
                        $this->plainTextExcerptForTicketNotify($data['message']),
                        ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
                        true
                    );
                } catch (\Throwable $e) {
                    Log::warning('ticket_reply_notify_failed', [
                        'ticket_id' => $ticket->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($mentionIds !== []) {
            $requestorId = $ticket->created_by_user_id ? (int) $ticket->created_by_user_id : null;
            $actorId = (int) $actor->id;
            $excerpt = $this->plainTextExcerptForTicketNotify($data['message']);
            foreach ($mentionIds as $uid) {
                if ($uid === $actorId) {
                    continue;
                }
                // Elak noti berganda: pemohon sudah terima ticket.reply untuk balasan staf awam.
                if (! $isInternal && ! $isRequestor && $requestorId !== null && $uid === $requestorId) {
                    continue;
                }
                $mentioned = User::query()->where('id', $uid)->where('is_active', true)->first();
                if (! $mentioned) {
                    continue;
                }
                try {
                    $this->notifications->notifyUser(
                        $mentioned,
                        'user',
                        'ticket',
                        'ticket.mention',
                        'Anda disebut dalam tiket '.$ticket->ticket_number,
                        $actor->name.' menyebut anda: '.$excerpt,
                        ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'mentioned_by_user_id' => $actorId],
                        true
                    );
                } catch (\Throwable $e) {
                    Log::warning('ticket_mention_notify_failed', [
                        'ticket_id' => $ticket->id,
                        'mentioned_user_id' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($isRequestor && ! $isInternal) {
            try {
                $ticket->refresh();
                $this->ticketAi->afterRequestorReply($ticket, (string) $data['message'], (int) $actor->id);
            } catch (\Throwable $e) {
                Log::error('ticket_aina_after_reply_failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->sendOk([
            'id' => $message->id,
            'ticket_id' => $message->support_ticket_id,
            'user_id' => $message->user_id,
            'message' => $message->message,
            'is_internal' => (bool) $message->is_internal,
            'created_at' => $message->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Cadangan ringkas AI untuk draf balasan ejen (tiket mesti sudah ditugaskan).
     */
    public function agentReplySuggestion(AgentReplySuggestionRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        if (! $this->canRespond($actor)) {
            return $this->sendError(403, 'FORBIDDEN', 'Only staff can request agent reply suggestions');
        }

        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        if ($ticket->status === 'closed') {
            return $this->sendError(400, 'BAD_REQUEST', 'Cannot suggest a reply for a closed ticket');
        }

        if (! $ticket->assigned_to_user_id) {
            return $this->sendError(400, 'BAD_REQUEST', 'Ticket must be assigned to an agent before AI suggestions are available');
        }

        $hint = $request->validated()['regenerate_prompt'] ?? null;
        $hint = is_string($hint) ? trim($hint) : null;
        if ($hint === '') {
            $hint = null;
        }

        try {
            $result = $this->ticketAi->agentAssistReplySuggestion($ticket, $hint);
        } catch (\Throwable $e) {
            Log::warning('ticket_agent_suggest_failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError(500, 'INTERNAL_ERROR', 'Could not generate suggestion');
        }

        if (isset($result['error'])) {
            return $this->sendError(502, 'UPSTREAM_ERROR', $result['error']);
        }

        return $this->sendOk(['suggestion' => $result['suggestion']]);
    }

    public function rejectAi(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        $isRequestor = (int) $ticket->created_by_user_id === (int) $actor->id;
        if (! $isRequestor && ! $this->canRespond($actor)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot reject AI for this ticket');
        }

        $deletedAiMessages = DB::transaction(function () use ($ticket) {
            $deleted = SupportTicketMessage::where('support_ticket_id', $ticket->id)
                ->where('is_ai_message', true)
                ->delete();

            $ticket->update([
                'ai_assistance_enabled' => false,
                'ai_awaiting_satisfaction' => false,
            ]);

            return $deleted;
        });

        $ticket->refresh();
        $ticket->load(['requestor', 'assignee']);

        return $this->sendOk([
            'ticket' => $this->formatTicket($ticket),
            'deleted_ai_messages' => $deletedAiMessages,
        ]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ticket = $this->baseQueryForActor($actor)->where('id', $id)->first();
        if (! $ticket) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket not found');
        }

        $isRequestor = (int) $ticket->created_by_user_id === (int) $actor->id;
        if (! $isRequestor && ! $this->canRespond($actor)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot close this ticket');
        }
        if (! $this->canMoveTo((string) $ticket->status, 'closed')) {
            return $this->sendError(409, 'INVALID_STATUS_TRANSITION', 'Invalid ticket status transition');
        }

        $ticket->update([
            'status' => 'closed',
            'closed_by_user_id' => $actor->id,
            'closed_at' => now(),
        ]);
        $ticket->refresh();
        $ticket->load(['requestor', 'assignee']);

        return $this->sendOk($this->formatTicket($ticket));
    }
}
