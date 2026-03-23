<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignSupportTicketRequest;
use App\Http\Requests\ReplySupportTicketRequest;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Http\Requests\UpdateSupportTicketRequest;
use App\Http\Traits\ApiResponse;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\UserHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportTicketController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected UserHierarchyService $hierarchy,
        protected AppNotificationService $notifications,
    ) {}

    private function actorLevel(User $user): string
    {
        return UserLevel::normalize($user->user_level ?? UserLevel::USER);
    }

    private function canReviewAssign(User $user): bool
    {
        return in_array($this->actorLevel($user), [UserLevel::INTERNAL_ADMIN, UserLevel::EXTERNAL_ADMIN, UserLevel::SUPER_ADMIN], true);
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

        $visibleIds = $this->visibleUserIds($actor);
        if ($level === UserLevel::USER) {
            return $query->where('created_by_user_id', $actor->id);
        }
        if ($level === UserLevel::AGENT) {
            return $query->where(function ($q) use ($actor) {
                $q->where('assigned_to_user_id', $actor->id)
                    ->orWhere('created_by_user_id', $actor->id);
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
            'module' => $ticket->module,
            'type' => $ticket->type,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
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
                'user_level' => $ticket->requestor->user_level,
            ] : null,
            'assignee' => $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'name' => $ticket->assignee->name,
                'email' => $ticket->assignee->email,
                'user_level' => $ticket->assignee->user_level,
            ] : null,
        ];
    }

    private function nextTicketNumber(): string
    {
        $lastId = (int) (SupportTicket::query()->max('id') ?? 0) + 1;

        return 'TKT-'.now()->format('Ymd').'-'.str_pad((string) $lastId, 6, '0', STR_PAD_LEFT);
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
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$qLower.'%']);
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
        if ($this->actorLevel($actor) !== UserLevel::USER) {
            return $this->sendError(403, 'FORBIDDEN', 'Only Level 4 users can create ticket');
        }

        $data = $request->validated();
        $ticket = DB::transaction(function () use ($actor, $data) {
            $ticket = SupportTicket::create([
                'ticket_number' => $this->nextTicketNumber(),
                'subject' => $data['subject'],
                'description' => $data['description'],
                'module' => $data['module'] ?? null,
                'type' => $data['type'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'new',
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
            'created_at' => $m->created_at?->toIso8601String(),
            'user' => $m->user ? [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'user_level' => $m->user->user_level,
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

        if ($level === UserLevel::USER) {
            if ((int) $ticket->created_by_user_id !== (int) $actor->id) {
                return $this->sendError(403, 'FORBIDDEN', 'You can update your own ticket only');
            }
            if (! in_array($ticket->status, ['new', 'pending_requestor'], true)) {
                return $this->sendError(409, 'TICKET_LOCKED', 'Ticket cannot be edited at current status');
            }
            unset($data['status']);
        }
        if (isset($data['status']) && ! $this->canMoveTo((string) $ticket->status, (string) $data['status'])) {
            return $this->sendError(409, 'INVALID_STATUS_TRANSITION', 'Invalid ticket status transition');
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

        if ($this->actorLevel($actor) !== UserLevel::USER || (int) $ticket->created_by_user_id !== (int) $actor->id) {
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
        if (! $this->canReviewAssign($actor)) {
            return $this->sendError(403, 'FORBIDDEN', 'Only Level 1/2 can assign ticket');
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
        if (! in_array($assignee->id, $this->visibleUserIds($actor), true)) {
            return $this->sendError(403, 'FORBIDDEN', 'Assignee outside your hierarchy scope');
        }

        $ticket->update([
            'assigned_to_user_id' => $assignee->id,
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now(),
            'status' => 'assigned',
        ]);

        if (! empty($data['note'])) {
            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'message' => '[ASSIGN NOTE] '.$data['note'],
                'is_internal' => true,
            ]);
        }

        $this->notifications->notifyUser(
            $assignee,
            'system',
            'ticket',
            'ticket.assigned',
            'Assigned ticket '.$ticket->ticket_number,
            $ticket->subject,
            ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
            true
        );

        $ticket->refresh();
        $ticket->load(['requestor', 'assignee']);

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

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'message' => $data['message'],
            'is_internal' => $isInternal,
        ]);

        $nextStatus = $data['status'] ?? null;
        if (! $nextStatus) {
            $nextStatus = $isRequestor ? 'pending_requestor' : 'in_progress';
        }
        if (! $this->canMoveTo((string) $ticket->status, (string) $nextStatus)) {
            return $this->sendError(409, 'INVALID_STATUS_TRANSITION', 'Invalid ticket status transition');
        }

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

        if (! $isRequestor && $ticket->requestor) {
            $this->notifications->notifyUser(
                $ticket->requestor,
                'system',
                'ticket',
                'ticket.reply',
                'Reply for '.$ticket->ticket_number,
                $data['message'],
                ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
                true
            );
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
