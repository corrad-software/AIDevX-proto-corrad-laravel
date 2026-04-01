<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLevel;
use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ChatMessage;
use App\Models\ChatMessageFavorite;
use App\Models\ChatSession;
use App\Models\ChatSessionFavorite;
use App\Models\Customer;
use App\Models\SyncedTicket;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\ChatAttachmentService;
use App\Services\Desk365Service;
use App\Services\OpenAIService;
use App\Services\UserHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected OpenAIService $openAIService,
        protected ChatAttachmentService $chatAttachment,
        protected Desk365Service $desk365,
        protected UserHierarchyService $hierarchy,
        protected AppNotificationService $appNotifications,
    ) {}

    /** Scope: user chat sessions (chat_type=user, owner only) */
    private function userChatScope()
    {
        return ChatSession::where('user_id', Auth::id())->where('chat_type', 'user');
    }

    /**
     * @return list<int>
     */
    private function visibleUserIdsForActor(): array
    {
        $actor = Auth::user();
        if (! $actor) {
            return [];
        }

        return $this->hierarchy->visibleUserIdsFor($actor, true);
    }

    /**
     * @return list<string>
     */
    private function visibleCustomerNamesForActor(User $actor): array
    {
        $level = UserLevel::normalize($actor->user_level ?? UserLevel::USER);
        if ($level === UserLevel::SUPER_ADMIN) {
            return [];
        }

        if (UserLevel::isEndUserTier($level)) {
            $customerNames = $actor->customers()->pluck('customers.customer_name')->filter()->map(fn ($n) => mb_strtolower((string) $n))->all();
            if (empty($customerNames) && $actor->customer_code) {
                $customerNames = Customer::where('customer_code', $actor->customer_code)
                    ->pluck('customer_name')
                    ->filter()
                    ->map(fn ($n) => mb_strtolower((string) $n))
                    ->all();
            }

            return array_values(array_unique($customerNames));
        }

        $visibleIds = $this->visibleUserIdsForActor();
        if ($visibleIds === []) {
            return [];
        }

        $customerNames = User::query()
            ->whereIn('id', $visibleIds)
            ->whereIn('user_level', [UserLevel::EXTERNAL_ADMIN, UserLevel::AGENT, UserLevel::USER, UserLevel::SECONDARY_USER])
            ->whereNotNull('customer_code')
            ->pluck('customer_code')
            ->unique()
            ->values()
            ->all();

        if ($customerNames === []) {
            return [];
        }

        return Customer::query()
            ->whereIn('customer_code', $customerNames)
            ->pluck('customer_name')
            ->filter()
            ->map(fn ($n) => mb_strtolower((string) $n))
            ->unique()
            ->values()
            ->all();
    }

    public function newSession(Request $request): JsonResponse
    {
        try {
            $thread = $this->openAIService->createThread();
        } catch (\Throwable $e) {
            Log::error('Chat newSession exception', ['message' => $e->getMessage()]);

            return $this->sendError(500, 'INTERNAL_ERROR', $e->getMessage());
        }

        if (isset($thread['error'])) {
            return $this->sendError(500, 'INTERNAL_ERROR', $thread['error']);
        }
        if (! isset($thread['id'])) {
            return $this->sendError(500, 'INTERNAL_ERROR', 'Failed to create chat session');
        }

        $sessionType = $request->input('session_type', 'solo');
        $participantIds = $request->input('participant_ids');
        $assignableAgentIds = $this->hierarchy->assignableAgentIdsForTicket(Auth::user());
        if (is_array($participantIds)) {
            $participantIds = array_values(array_filter(array_map('intval', $participantIds)));
            $participantIds = array_values(array_intersect(
                $participantIds,
                User::whereIn('id', $participantIds)->pluck('id')->all()
            ));
            $participantIds = array_values(array_intersect($participantIds, $assignableAgentIds));
        } else {
            $participantIds = null;
        }

        if ($sessionType === 'group' && empty($participantIds)) {
            return $this->sendError(422, 'VALIDATION_ERROR', 'Please select at least one assignable agent');
        }

        $session = ChatSession::create([
            'openai_thread_id' => $thread['id'],
            'title' => $request->input('title', 'New Chat'),
            'module_filter' => $request->input('module_filter'),
            'user_id' => Auth::id(),
            'session_type' => in_array($sessionType, ['solo', 'group']) ? $sessionType : 'solo',
            'chat_type' => 'support',
            'participant_ids' => $participantIds,
            'desk365_ticket_id' => $request->input('desk365_ticket_id'),
        ]);

        if ($session->session_type === 'group' && ! empty($participantIds)) {
            $inviter = Auth::user();
            foreach ($participantIds as $pid) {
                if ((int) $pid === (int) Auth::id()) {
                    continue;
                }
                $invited = User::find((int) $pid);
                if ($invited && $inviter) {
                    $this->appNotifications->notifyGroupChatInvite(
                        $invited,
                        $inviter,
                        $session->id,
                        (string) $session->title
                    );
                }
            }
        }

        $sessionData = $session->toArray();
        if ($session->session_type === 'group' && ! empty($session->participant_ids)) {
            $ids = array_unique(array_merge(
                $session->user_id ? [$session->user_id] : [],
                $session->participant_ids ?? []
            ));
            $sessionData['participants'] = User::whereIn('id', $ids)->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values()->all();
        } else {
            $sessionData['participants'] = [];
        }

        return $this->sendCreated([
            'session' => $sessionData,
            'messages' => [],
        ]);
    }

    public function sendMessage(Request $request, int $sessionId): JsonResponse
    {
        set_time_limit(120);

        $request->validate([
            'message' => 'required|string|min:1|max:2000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,gif|max:4096',
            'reply_to_message_id' => 'nullable|integer|exists:chat_messages,id',
            'reply_to_user_id' => 'nullable|integer|exists:users,id',
            'mention_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $session = ChatSession::where('id', $sessionId)
            ->accessibleBy(Auth::id())
            ->first();

        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }

        $userMessage = $request->input('message');
        $files = $request->file('attachments');
        if ($files && ! is_array($files)) {
            $files = [$files];
        }
        $files = $files ?? [];

        $imageFileIds = [];
        $imageBase64Urls = [];
        $documentTexts = [];

        if ($files) {
            foreach ($files as $file) {
                if (! $this->chatAttachment->isSupported($file)) {
                    continue;
                }
                if ($this->chatAttachment->isImage($file)) {
                    $result = $this->chatAttachment->uploadImageToOpenAI($file, $this->openAIService);
                    if ($result) {
                        $imageFileIds[] = $result;
                    }
                    $b64 = $this->chatAttachment->getImageAsBase64($file);
                    if ($b64) {
                        $imageBase64Urls[] = $b64;
                    }
                } else {
                    $text = $this->chatAttachment->extractTextFromDocument($file);
                    if ($text) {
                        $documentTexts[] = "[{$file->getClientOriginalName()}]\n{$text}";
                    }
                }
            }
        }

        $documentText = ! empty($documentTexts) ? implode("\n\n", $documentTexts) : null;
        if ($documentText && mb_strlen($documentText) > 50000) {
            $documentText = mb_substr($documentText, 0, 50000)."\n\n[... dokumen dipendekkan ...]";
        }

        $replyToMsgId = $request->input('reply_to_message_id') ? (int) $request->input('reply_to_message_id') : null;
        $replyToUserId = $request->input('reply_to_user_id') ? (int) $request->input('reply_to_user_id') : null;
        $mentionToUserId = $request->input('mention_to_user_id') ? (int) $request->input('mention_to_user_id') : null;
        if ($replyToMsgId && ! ChatMessage::where('id', $replyToMsgId)->where('chat_session_id', $session->id)->exists()) {
            return $this->sendError(400, 'BAD_REQUEST', 'Reply message must belong to this chat session');
        }

        $userMsg = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $userMessage,
            'reply_to_message_id' => $replyToMsgId,
            'reply_to_user_id' => $replyToUserId,
            'mention_to_user_id' => $mentionToUserId,
        ]);

        if ($session->messages()->count() === 1) {
            $session->update(['title' => mb_substr($userMessage, 0, 60)]);
        }

        if ($mentionToUserId) {
            $userMsg->load('mentionToUser:id,name');
            broadcast(new ChatMessageSent($userMsg));

            return $this->sendOk($userMsg);
        }

        broadcast(new ChatMessageSent($userMsg));

        try {
            $response = $this->openAIService->sendMessage(
                $session->openai_thread_id,
                $userMessage,
                $imageFileIds,
                $documentText
            );
        } catch (\Throwable $e) {
            Log::error('Chat sendMessage exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $msg = $this->normalizeOpenAIError($e->getMessage());

            return $this->sendError(500, 'INTERNAL_ERROR', $msg);
        }

        $isServerError = isset($response['error']) && (
            str_contains(strtolower($response['error']), 'server had an error') ||
            str_contains(strtolower($response['error']), 'server_error') ||
            str_contains(strtolower($response['error']), 'something went wrong')
        );

        if (isset($response['error']) && ! empty($imageBase64Urls) && $isServerError) {
            Log::info('Chat: Assistants API failed with images, trying Chat Completions vision fallback');
            $visionResponse = $this->openAIService->chatWithVision($userMessage, $documentText, $imageBase64Urls);
            if (! isset($visionResponse['error'])) {
                $response = $visionResponse;
            } else {
                Log::warning('Chat: vision fallback also failed', ['error' => $visionResponse['error']]);
                $response = $this->openAIService->sendMessage(
                    $session->openai_thread_id,
                    $userMessage."\n\n[Nota: Imej tidak dapat diproses. Sila hantar soalan tanpa gambar atau cuba imej lebih kecil.]",
                    [],
                    $documentText
                );
            }
        }

        if (isset($response['error'])) {
            $errRaw = $response['error'];
            $isRateLimit = str_contains(strtolower($errRaw), 'rate_limit_exceeded') || str_contains(strtolower($errRaw), 'rate limit');
            $retries = [30, 60, 120]; // 30s, 1min, 2min — lebih lama untuk rate limit OpenAI
            foreach ($retries as $delay) {
                if ($isRateLimit) {
                    Log::info("Chat: rate limit hit, retrying after {$delay}s");
                    sleep($delay);
                    $response = $this->openAIService->sendMessage(
                        $session->openai_thread_id,
                        $userMessage,
                        $imageFileIds,
                        $documentText
                    );
                    if (! isset($response['error'])) {
                        break;
                    }
                    $errRaw = $response['error'];
                    $isRateLimit = str_contains(strtolower($errRaw), 'rate_limit_exceeded') || str_contains(strtolower($errRaw), 'rate limit');
                } else {
                    break;
                }
            }
            if (isset($response['error'])) {
                $msg = $this->normalizeOpenAIError($response['error']);

                return $this->sendError(500, 'INTERNAL_ERROR', $msg);
            }
        }

        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $response['content'],
            'citations' => $response['citations'] ?? [],
        ]);

        broadcast(new ChatMessageSent($assistantMessage));

        return $this->sendOk($assistantMessage);
    }

    public function getSession(int $sessionId): JsonResponse
    {
        $session = ChatSession::with(['messages.replyToMessage', 'messages.replyToUser'])
            ->where('id', $sessionId)
            ->accessibleBy(Auth::id())
            ->first();

        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }

        $data = $session->toArray();
        $data['is_favorited'] = ChatSessionFavorite::where('user_id', Auth::id())
            ->where('chat_session_id', $sessionId)
            ->exists();
        $ids = array_unique(array_merge(
            $session->user_id ? [$session->user_id] : [],
            $session->participant_ids ?? []
        ));
        if (! empty($ids)) {
            $participants = User::whereIn('id', $ids)->get(['id', 'name', 'email'])->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values()->all();
            $data['participants'] = $participants;
        } else {
            $data['participants'] = [];
        }

        return $this->sendOk($data);
    }

    public function updateSession(Request $request, int $sessionId): JsonResponse
    {
        $session = ChatSession::where('id', $sessionId)
            ->accessibleBy(Auth::id())
            ->first();

        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }

        $updates = [];

        $oldParticipantIds = $session->participant_ids ?? [];

        if ($session->session_type === 'group') {
            $participantIds = $request->input('participant_ids');
            if (is_array($participantIds)) {
                $updates['participant_ids'] = array_values(array_unique(array_filter(array_map('intval', $participantIds))));
            }
        }

        if ($request->has('desk365_ticket_id')) {
            $val = $request->input('desk365_ticket_id');
            $updates['desk365_ticket_id'] = is_string($val) && trim($val) !== '' ? trim($val) : null;
        }

        if (! empty($updates)) {
            if (isset($updates['participant_ids'])) {
                $validIds = User::whereIn('id', $updates['participant_ids'])->pluck('id')->all();
                $updates['participant_ids'] = array_values(array_unique($validIds));
                $added = array_values(array_diff($updates['participant_ids'], $oldParticipantIds));
                $inviter = Auth::user();
                foreach ($added as $pid) {
                    if ((int) $pid === (int) Auth::id()) {
                        continue;
                    }
                    $invited = User::find((int) $pid);
                    if ($invited && $inviter) {
                        $this->appNotifications->notifyGroupChatInvite(
                            $invited,
                            $inviter,
                            $session->id,
                            (string) ($session->title ?? '')
                        );
                    }
                }
            }
            $session->update($updates);
        }

        $session = $session->fresh();
        $data = $session->toArray();
        $ids = array_unique(array_merge(
            $session->user_id ? [$session->user_id] : [],
            $session->participant_ids ?? []
        ));
        if (! empty($ids)) {
            $data['participants'] = User::whereIn('id', $ids)->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values()->all();
        } else {
            $data['participants'] = [];
        }

        return $this->sendOk($data);
    }

    public function mySessions(): JsonResponse
    {
        $userId = Auth::id();
        $sessions = ChatSession::accessibleBy($userId)
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get();

        $favSessionIds = ChatSessionFavorite::where('user_id', $userId)
            ->whereIn('chat_session_id', $sessions->pluck('id'))
            ->pluck('chat_session_id')
            ->all();

        $data = $sessions->map(function ($s) use ($favSessionIds) {
            $arr = $s->toArray();
            $arr['is_favorited'] = in_array($s->id, $favSessionIds);

            return $arr;
        })->all();

        return $this->sendOk($data);
    }

    public function allSessions(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);
        $actor = $request->user();
        $level = UserLevel::normalize($actor->user_level ?? UserLevel::USER);
        $visibleIds = $this->visibleUserIdsForActor();

        $baseQuery = ChatSession::query();
        if ($level !== UserLevel::SUPER_ADMIN) {
            if ($visibleIds === []) {
                $baseQuery->whereRaw('1 = 0');
            } else {
                $baseQuery->whereIn('user_id', $visibleIds);
            }
        }

        $total = (clone $baseQuery)->count();
        $sessions = $baseQuery->with('user')
            ->orderBy('updated_at', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return $this->sendOk($sessions, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    public function deleteSession(int $sessionId): JsonResponse
    {
        ChatSession::where('id', $sessionId)
            ->accessibleBy(Auth::id())
            ->delete();

        return $this->sendOk(['success' => true]);
    }

    public function toggleSessionFavorite(int $sessionId): JsonResponse
    {
        $session = ChatSession::accessibleBy(Auth::id())->find($sessionId);
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }

        $fav = ChatSessionFavorite::firstOrCreate([
            'user_id' => Auth::id(),
            'chat_session_id' => $sessionId,
        ]);

        if ($fav->wasRecentlyCreated) {
            return $this->sendOk(['favorited' => true]);
        }
        $fav->delete();

        return $this->sendOk(['favorited' => false]);
    }

    /**
     * List tickets. Prefer DB (synced_tickets) with pagination. Fallback to Desk365 API.
     */
    public function tickets(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(max(10, (int) $request->input('limit', 20)), 100);
        $q = $request->input('q', '');

        $hasDbTickets = SyncedTicket::query()->exists();
        if ($hasDbTickets) {
            $query = SyncedTicket::query()->orderByDesc('created_at');

            $user = Auth::user();
            $customerNames = $this->visibleCustomerNamesForActor($user);
            if (! empty($customerNames)) {
                $query->where(function ($builder) use ($customerNames) {
                    foreach ($customerNames as $name) {
                        $builder->orWhereRaw('LOWER(company_name) LIKE ?', ['%'.addcslashes($name, '%_').'%']);
                    }
                });
            } elseif (UserLevel::normalize($user->user_level ?? UserLevel::USER) !== UserLevel::SUPER_ADMIN) {
                $query->whereRaw('1 = 0');
            }

            if ($q !== '') {
                $qLower = mb_strtolower($q);
                $query->where(function ($builder) use ($qLower) {
                    $builder->whereRaw('LOWER(ticket_number) LIKE ?', ["%{$qLower}%"])
                        ->orWhereRaw('LOWER(subject) LIKE ?', ["%{$qLower}%"])
                        ->orWhereRaw('LOWER(description) LIKE ?', ["%{$qLower}%"])
                        ->orWhereRaw('LOWER(contact_name) LIKE ?', ["%{$qLower}%"])
                        ->orWhereRaw('LOWER(company_name) LIKE ?', ["%{$qLower}%"])
                        ->orWhereRaw('LOWER(assigned_agent) LIKE ?', ["%{$qLower}%"])
                        ->orWhereRaw('LOWER(module) LIKE ?', ["%{$qLower}%"]);
                });
            }
            $total = $query->count();
            $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();
            $data = $rows->map(fn ($t) => [
                'ticket_number' => $t->ticket_number,
                'subject' => $t->subject,
                'description' => $t->description,
                'sub_category' => $t->module,
                'type' => $t->type,
                'priority' => $t->priority,
                'status' => $t->status,
                'contact_name' => $t->contact_name,
                'company_name' => $t->company_name,
                'assigned_agent' => $t->assigned_agent,
                'created_time' => $t->created_time,
            ])->all();

            return $this->sendOk($data, [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ]);
        }

        if (! $this->desk365->isConfigured()) {
            return $this->sendOk([], ['page' => 1, 'limit' => $limit, 'total' => 0, 'totalPages' => 0]);
        }

        $params = ['limit' => 500];
        $tickets = $this->desk365->listTicketsForChat($params);
        if (isset($tickets['error'])) {
            return $this->sendError(502, 'UPSTREAM_ERROR', $tickets['error']);
        }

        $data = $this->normalizeDesk365Tickets(is_array($tickets) ? $tickets : []);

        $user = Auth::user();
        $customerNames = $this->visibleCustomerNamesForActor($user);
        if (! empty($customerNames)) {
            $data = array_values(array_filter($data, function ($t) use ($customerNames) {
                $cn = mb_strtolower((string) ($t['company_name'] ?? ''));
                foreach ($customerNames as $name) {
                    if (str_contains($cn, $name) || str_contains($name, $cn)) {
                        return true;
                    }
                }

                return false;
            }));
        } elseif (UserLevel::normalize($user->user_level ?? UserLevel::USER) !== UserLevel::SUPER_ADMIN) {
            $data = [];
        }

        $total = count($data);
        $offset = ($page - 1) * $limit;
        $paginated = array_slice($data, $offset, $limit);

        return $this->sendOk($paginated, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    private function normalizeDesk365Tickets(array $tickets): array
    {
        return array_map(fn ($t) => [
            'ticket_number' => $t['Ticket Number'] ?? $t['ticket_number'] ?? '',
            'subject' => $t['Subject'] ?? $t['subject'] ?? '',
            'description' => $t['Description'] ?? $t['description'] ?? '',
            'sub_category' => $t['SubCategory'] ?? $t['sub_category'] ?? '',
            'type' => $t['Type'] ?? $t['type'] ?? '',
            'priority' => $t['Priority'] ?? $t['priority'] ?? '',
            'status' => $t['Status'] ?? $t['status'] ?? '',
            'contact_name' => $t['Contact Name'] ?? $t['contact_name'] ?? '',
            'company_name' => $t['Company Name'] ?? $t['company_name'] ?? '',
            'assigned_agent' => $t['Assigned Agent'] ?? $t['assigned_agent'] ?? '',
            'created_time' => $t['Created Time'] ?? $t['created_time'] ?? '',
        ], $tickets);
    }

    /**
     * Get ticket detail. From DB when available; else Desk365 API.
     */
    public function ticketDetail(string $ticketId): JsonResponse
    {
        $actor = Auth::user();
        $level = UserLevel::normalize($actor->user_level ?? UserLevel::USER);
        $customerNames = $this->visibleCustomerNamesForActor($actor);

        $ticket = SyncedTicket::where('ticket_number', $ticketId)->first();
        if ($ticket) {
            if ($level !== UserLevel::SUPER_ADMIN) {
                $cn = mb_strtolower((string) ($ticket->company_name ?? ''));
                $allowed = false;
                foreach ($customerNames as $name) {
                    if (str_contains($cn, $name) || str_contains($name, $cn)) {
                        $allowed = true;
                        break;
                    }
                }
                if (! $allowed) {
                    return $this->sendError(403, 'FORBIDDEN', 'Tiada akses ke tiket ini');
                }
            }

            return $this->sendOk([
                'ticket' => [
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    'module' => $ticket->module,
                    'status' => $ticket->status,
                    'type' => $ticket->type,
                    'priority' => $ticket->priority,
                    'contact_name' => $ticket->contact_name,
                    'company_name' => $ticket->company_name,
                    'assigned_agent' => $ticket->assigned_agent,
                    'created_time' => $ticket->created_time,
                ],
                'conversations' => [],
            ]);
        }

        if (! $this->desk365->isConfigured()) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket tidak dijumpai');
        }

        $detail = $this->desk365->getTicketDetails($ticketId);
        if (isset($detail['error'])) {
            return $this->sendError(404, 'NOT_FOUND', 'Ticket tidak dijumpai');
        }

        if ($level !== UserLevel::SUPER_ADMIN) {
            $companyName = mb_strtolower((string) ($detail['Company Name'] ?? $detail['company_name'] ?? ''));
            $allowed = false;
            foreach ($customerNames as $name) {
                if (str_contains($companyName, $name) || str_contains($name, $companyName)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                return $this->sendError(403, 'FORBIDDEN', 'Tiada akses ke tiket ini');
            }
        }

        $conversations = $this->desk365->getTicketConversations($ticketId);
        $conversationsList = $conversations['conversations'] ?? $conversations['data'] ?? $conversations['results'] ?? [];

        return $this->sendOk([
            'ticket' => $detail,
            'conversations' => $conversationsList,
        ]);
    }

    /**
     * List favorite messages for current user (3–5 + pagination).
     */
    public function favorites(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 5), 20);
        $page = (int) $request->input('page', 1);

        $query = ChatMessageFavorite::with(['chatMessage.session'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc');

        $total = $query->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();

        $items = $rows->map(fn ($f) => [
            'id' => $f->id,
            'message' => $f->chatMessage,
            'session' => $f->chatMessage?->session,
            'created_at' => $f->created_at?->toIso8601String(),
        ]);

        return $this->sendOk($items, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    /**
     * Toggle favorite on a message.
     */
    public function toggleFavorite(int $messageId): JsonResponse
    {
        $msg = ChatMessage::find($messageId);
        if (! $msg) {
            return $this->sendError(404, 'NOT_FOUND', 'Mesej tidak dijumpai');
        }

        $session = ChatSession::where('id', $msg->chat_session_id)->accessibleBy(Auth::id())->first();
        if (! $session) {
            return $this->sendError(403, 'FORBIDDEN', 'Tiada akses ke sesi ini');
        }

        $fav = ChatMessageFavorite::firstOrCreate([
            'user_id' => Auth::id(),
            'chat_message_id' => $messageId,
        ]);
        if ($fav->wasRecentlyCreated) {
            return $this->sendOk(['favorited' => true]);
        }
        $fav->delete();

        return $this->sendOk(['favorited' => false]);
    }

    /**
     * Search messages in a session.
     */
    public function searchMessages(Request $request, int $sessionId): JsonResponse
    {
        $session = ChatSession::where('id', $sessionId)->accessibleBy(Auth::id())->first();
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Sesi tidak dijumpai');
        }

        $q = $request->input('q');
        if (empty($q) || strlen($q) < 2) {
            return $this->sendOk([], ['count' => 0]);
        }

        $messages = ChatMessage::where('chat_session_id', $sessionId)
            ->where('content', 'like', '%'.addcslashes($q, '%_').'%')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return $this->sendOk($messages, ['count' => $messages->count()]);
    }

    /**
     * Suggestions for agent (soalan lazim, quick actions).
     */
    public function suggestions(): JsonResponse
    {
        $suggestions = $this->chatSuggestionsList();

        return $this->sendOk($suggestions);
    }

    private function chatSuggestionsList(): array
    {
        return [
            ['id' => 'gl-jurnal', 'label' => 'Macam mana nak buat jurnal GL?', 'module' => 'General Ledger'],
            ['id' => 'payroll-bulanan', 'label' => 'Cara proses payroll bulanan?', 'module' => 'Payroll'],
            ['id' => 'vendor-baru', 'label' => 'Macam mana nak tambah vendor baru?', 'module' => 'Purchasing'],
            ['id' => 'cashbook-reconcile', 'label' => 'Cara reconcile cashbook?', 'module' => 'Cashbook'],
            ['id' => 'ar-invoice', 'label' => 'Cara buat invoice AR?', 'module' => 'Account Receivable'],
        ];
    }

    // ─── User Chat (chat_type=user, no ticket, AI: KB only, no SQL/schema) ───

    public function newUserChatSession(Request $request): JsonResponse
    {
        try {
            $thread = $this->openAIService->createThread();
        } catch (\Throwable $e) {
            Log::error('UserChat newSession exception', ['message' => $e->getMessage()]);

            return $this->sendError(500, 'INTERNAL_ERROR', $e->getMessage());
        }
        if (isset($thread['error'])) {
            return $this->sendError(500, 'INTERNAL_ERROR', $thread['error']);
        }
        if (! isset($thread['id'])) {
            return $this->sendError(500, 'INTERNAL_ERROR', 'Failed to create chat session');
        }
        $session = ChatSession::create([
            'openai_thread_id' => $thread['id'],
            'title' => $request->input('title', 'New Chat'),
            'module_filter' => $request->input('module_filter'),
            'user_id' => Auth::id(),
            'session_type' => 'solo',
            'chat_type' => 'user',
        ]);

        return $this->sendCreated(['session' => $session->toArray(), 'messages' => []]);
    }

    public function myUserChatSessions(): JsonResponse
    {
        $userId = Auth::id();
        $sessions = $this->userChatScope()->orderBy('updated_at', 'desc')->limit(20)->get();
        $favIds = ChatSessionFavorite::where('user_id', $userId)
            ->whereIn('chat_session_id', $sessions->pluck('id'))
            ->pluck('chat_session_id')
            ->all();
        $data = $sessions->map(fn ($s) => array_merge($s->toArray(), [
            'is_favorited' => in_array($s->id, $favIds),
        ]))->all();

        return $this->sendOk($data);
    }

    public function getUserChatSession(int $sessionId): JsonResponse
    {
        $session = $this->userChatScope()->with(['messages.replyToMessage', 'messages.replyToUser'])
            ->find($sessionId);
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }
        $data = $session->toArray();
        $data['is_favorited'] = ChatSessionFavorite::where('user_id', Auth::id())
            ->where('chat_session_id', $sessionId)->exists();

        return $this->sendOk($data);
    }

    public function sendUserChatMessage(Request $request, int $sessionId): JsonResponse
    {
        set_time_limit(120);
        $request->validate([
            'message' => 'required|string|min:1|max:2000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,gif|max:4096',
        ]);
        $session = $this->userChatScope()->find($sessionId);
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }
        $userMessage = $request->input('message');
        $files = $request->file('attachments');
        if ($files && ! is_array($files)) {
            $files = [$files];
        }
        $files = $files ?? [];
        $imageFileIds = [];
        $imageBase64Urls = [];
        $documentTexts = [];
        foreach ($files as $file) {
            if (! $this->chatAttachment->isSupported($file)) {
                continue;
            }
            if ($this->chatAttachment->isImage($file)) {
                $r = $this->chatAttachment->uploadImageToOpenAI($file, $this->openAIService);
                if ($r) {
                    $imageFileIds[] = $r;
                }
                $b = $this->chatAttachment->getImageAsBase64($file);
                if ($b) {
                    $imageBase64Urls[] = $b;
                }
            } else {
                $t = $this->chatAttachment->extractTextFromDocument($file);
                if ($t) {
                    $documentTexts[] = "[{$file->getClientOriginalName()}]\n{$t}";
                }
            }
        }
        $documentText = ! empty($documentTexts) ? implode("\n\n", $documentTexts) : null;
        if ($documentText && mb_strlen($documentText) > 50000) {
            $documentText = mb_substr($documentText, 0, 50000)."\n\n[... dokumen dipendekkan ...]";
        }
        $userMsg = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);
        if ($session->messages()->count() === 1) {
            $session->update(['title' => mb_substr($userMessage, 0, 60)]);
        }
        broadcast(new ChatMessageSent($userMsg));
        try {
            $response = $this->openAIService->sendMessage(
                $session->openai_thread_id,
                $userMessage,
                $imageFileIds,
                $documentText,
                true
            );
        } catch (\Throwable $e) {
            Log::error('UserChat sendMessage exception', ['message' => $e->getMessage()]);

            return $this->sendError(500, 'INTERNAL_ERROR', $this->normalizeOpenAIError($e->getMessage()));
        }
        if (isset($response['error']) && ! empty($imageBase64Urls)) {
            $isServerErr = str_contains(strtolower($response['error']), 'server') || str_contains(strtolower($response['error']), 'something went wrong');
            if ($isServerErr) {
                $visionRes = $this->openAIService->chatWithVision($userMessage, $documentText, $imageBase64Urls);
                if (! isset($visionRes['error'])) {
                    $response = $visionRes;
                }
            }
        }
        if (isset($response['error'])) {
            return $this->sendError(500, 'INTERNAL_ERROR', $this->normalizeOpenAIError($response['error']));
        }
        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $response['content'],
            'citations' => $response['citations'] ?? [],
        ]);
        broadcast(new ChatMessageSent($assistantMessage));

        return $this->sendOk($assistantMessage);
    }

    public function updateUserChatSession(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->userChatScope()->find($sessionId);
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }
        $updates = [];
        if ($request->has('module_filter')) {
            $updates['module_filter'] = $request->input('module_filter');
        }
        if (! empty($updates)) {
            $session->update($updates);
        }

        return $this->sendOk($session->fresh()->toArray());
    }

    public function deleteUserChatSession(int $sessionId): JsonResponse
    {
        $this->userChatScope()->where('id', $sessionId)->delete();

        return $this->sendOk(['success' => true]);
    }

    public function toggleUserChatSessionFavorite(int $sessionId): JsonResponse
    {
        $session = $this->userChatScope()->find($sessionId);
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Chat session not found');
        }
        $fav = ChatSessionFavorite::firstOrCreate([
            'user_id' => Auth::id(),
            'chat_session_id' => $sessionId,
        ]);
        if (! $fav->wasRecentlyCreated) {
            $fav->delete();

            return $this->sendOk(['favorited' => false]);
        }

        return $this->sendOk(['favorited' => true]);
    }

    public function searchUserChatMessages(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->userChatScope()->find($sessionId);
        if (! $session) {
            return $this->sendError(404, 'NOT_FOUND', 'Sesi tidak dijumpai');
        }
        $q = $request->input('q');
        if (empty($q) || strlen($q) < 2) {
            return $this->sendOk([], ['count' => 0]);
        }
        $messages = ChatMessage::where('chat_session_id', $sessionId)
            ->where('content', 'like', '%'.addcslashes($q, '%_').'%')
            ->orderBy('created_at', 'desc')->limit(50)->get();

        return $this->sendOk($messages, ['count' => $messages->count()]);
    }

    public function userChatFavorites(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 5), 20);
        $page = (int) $request->input('page', 1);
        $sessionIds = $this->userChatScope()->pluck('id');
        $query = ChatMessageFavorite::with(['chatMessage.session'])
            ->where('user_id', Auth::id())
            ->whereHas('chatMessage', fn ($q) => $q->whereIn('chat_session_id', $sessionIds))
            ->orderBy('created_at', 'desc');
        $total = $query->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();
        $items = $rows->map(fn ($f) => [
            'id' => $f->id,
            'message' => $f->chatMessage,
            'session' => $f->chatMessage?->session,
            'created_at' => $f->created_at?->toIso8601String(),
        ]);

        return $this->sendOk($items, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    public function toggleUserChatMessageFavorite(int $messageId): JsonResponse
    {
        $msg = ChatMessage::find($messageId);
        if (! $msg) {
            return $this->sendError(404, 'NOT_FOUND', 'Mesej tidak dijumpai');
        }
        $session = $this->userChatScope()->find($msg->chat_session_id);
        if (! $session) {
            return $this->sendError(403, 'FORBIDDEN', 'Tiada akses ke sesi ini');
        }
        $fav = ChatMessageFavorite::firstOrCreate([
            'user_id' => Auth::id(),
            'chat_message_id' => $messageId,
        ]);
        if (! $fav->wasRecentlyCreated) {
            $fav->delete();

            return $this->sendOk(['favorited' => false]);
        }

        return $this->sendOk(['favorited' => true]);
    }

    public function userChatSuggestions(): JsonResponse
    {
        return $this->sendOk($this->chatSuggestionsList());
    }

    /**
     * Terjemah ralat OpenAI ke mesej mesra pengguna (BM).
     */
    private function normalizeOpenAIError(string $raw): string
    {
        $lower = strtolower($raw);
        // rate_limit_exceeded = terlalu banyak permintaan (bukan kuota/kredit habis)
        if (str_contains($lower, 'rate_limit_exceeded') || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests')) {
            return 'Bukan masalah kredit. Terlalu banyak permintaan ke API AI (rate limit). Sila tunggu 2–3 minit dan cuba semula.';
        }
        if (str_contains($lower, 'insufficient_quota') || (str_contains($lower, 'exceeded your current quota') && ! str_contains($lower, 'rate_limit'))) {
            return 'Had kuota API OpenAI telah habis. Sila tambah kredit di dashboard OpenAI (billing.openai.com) atau hubungi pentadbir sistem.';
        }
        if (str_contains($lower, 'invalid api key') || str_contains($lower, 'incorrect api key')) {
            return 'API key OpenAI tidak sah. Sila semak konfigurasi OPENAI_API_KEY.';
        }
        if (str_contains($lower, 'server had an error') || str_contains($lower, 'something went wrong')) {
            return 'Server AI sibuk. Sila cuba semula dalam beberapa minit.';
        }

        return $raw;
    }
}
