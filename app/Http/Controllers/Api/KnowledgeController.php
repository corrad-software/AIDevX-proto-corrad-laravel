<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadKnowledgeDocRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Desk365SyncLog;
use App\Models\InternalTicketSyncLog;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeExtractSyncLog;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Desk365Service;
use App\Services\InternalTicketSyncService;
use App\Services\MyfisDbService;
use App\Services\OpenAIService;
use App\Services\TicketSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected OpenAIService $openAIService,
        protected MyfisDbService $myfisDb,
        protected Desk365Service $desk365,
        protected TicketSyncService $ticketSync,
        protected InternalTicketSyncService $internalTicketSync,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);
        $q = $request->input('q');
        $module = $request->input('module');

        $query = KnowledgeDocument::query();

        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('original_filename', 'like', "%{$q}%");
            });
        }

        if ($module) {
            $query->where('module', $module);
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return $this->sendOk($rows, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    public function upload(UploadKnowledgeDocRequest $request): JsonResponse
    {

        $file = $request->file('file');
        $filename = strtolower(preg_replace('/[^a-zA-Z0-9.\-_]/', '-', $file->getClientOriginalName()));
        $path = $file->storeAs('knowledge', $filename, 'public');

        $doc = KnowledgeDocument::create([
            'name' => $request->input('name', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'module' => $request->input('module'),
            'status' => 'pending',
            'uploaded_by' => Auth::id(),
        ]);

        // Upload to OpenAI Vector Store
        try {
            $fullPath = Storage::disk('public')->path($path);
            $result = $this->openAIService->uploadFileToVectorStore($fullPath, $filename);

            if (isset($result['file_id'])) {
                $doc->update([
                    'openai_file_id' => $result['file_id'],
                    'status' => 'uploaded',
                ]);
            } else {
                $doc->update(['status' => 'failed', 'notes' => json_encode($result)]);
            }
        } catch (\Exception $e) {
            $doc->update(['status' => 'failed', 'notes' => $e->getMessage()]);
        }

        return $this->sendCreated($doc->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $doc = KnowledgeDocument::find($id);
        if (! $doc) {
            return $this->sendError(404, 'NOT_FOUND', 'Document not found');
        }

        if ($doc->openai_file_id) {
            $this->openAIService->deleteFile($doc->openai_file_id);
        }

        Storage::disk('public')->delete($doc->file_path);
        $doc->delete();

        return $this->sendOk(['success' => true]);
    }

    public function modules(): JsonResponse
    {
        $modules = [
            'Cashbook', 'Account Receivable', 'Account Payable',
            'General Ledger', 'Payroll', 'Purchasing', 'Vendor Portal',
            'Debtor Portal', 'Credit Control', 'Investment', 'Loan',
            'Asset', 'Budget', 'Staff Portal', 'Student Finance', 'Setup & Maintenance',
        ];

        return $this->sendOk($modules);
    }

    public function setup(): JsonResponse
    {
        $vectorStoreId = config('services.openai.vector_store_id');
        $assistantId = config('services.openai.assistant_id');

        if (! $vectorStoreId) {
            $vs = $this->openAIService->createVectorStore('KERISI Knowledge Base');
            $vectorStoreId = $vs['id'] ?? null;
        }

        if (! $assistantId) {
            $assistant = $this->openAIService->createAssistant();
            $assistantId = $assistant['id'] ?? null;
        }

        return $this->sendOk([
            'vector_store_id' => $vectorStoreId,
            'assistant_id' => $assistantId,
            'message' => 'Copy these IDs to your .env file: OPENAI_VECTOR_STORE_ID and OPENAI_ASSISTANT_ID',
        ]);
    }

    public function setupUserChatAssistant(): JsonResponse
    {
        try {
            $code = Artisan::call('kerisi:setup-user-chat');
            $output = trim(Artisan::output());

            if ($code !== 0) {
                return $this->sendError(500, 'INTERNAL_ERROR', 'Failed to setup user chat assistant', [
                    'exit_code' => $code,
                    'output' => Str::limit($output, 65000),
                ]);
            }

            $assistantId = config('services.openai.user_chat_assistant_id');
            if (! $assistantId && preg_match('/OPENAI_USER_CHAT_ASSISTANT_ID=([a-zA-Z0-9_\-]+)/', $output, $m)) {
                $assistantId = $m[1];
            }
            if (! $assistantId && preg_match('/ID:\s*([a-zA-Z0-9_\-]+)/', $output, $m)) {
                $assistantId = $m[1];
            }

            return $this->sendOk([
                'assistant_id' => $assistantId,
                'tools' => ['file_search'],
                'message' => 'User Chat assistant is ready. Add OPENAI_USER_CHAT_ASSISTANT_ID to .env if needed.',
                'output' => Str::limit($output, 65000),
            ]);
        } catch (\Throwable $e) {
            return $this->sendError(500, 'INTERNAL_ERROR', 'Failed to setup user chat assistant', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function upgradeAssistant(): JsonResponse
    {
        $assistantId = config('services.openai.assistant_id');

        if (! $assistantId) {
            return $this->sendError(400, 'BAD_REQUEST', 'OPENAI_ASSISTANT_ID not configured');
        }

        $result = $this->openAIService->updateAssistant($assistantId);

        return $this->sendOk([
            'assistant_id' => $result['id'] ?? null,
            'tools' => array_column($result['tools'] ?? [], 'type'),
            'message' => 'Assistant updated with database query capability',
        ]);
    }

    public function dbStatus(): JsonResponse
    {
        $connected = $this->myfisDb->isConnected();

        return $this->sendOk([
            'connected' => $connected,
            'host' => env('MYFIS_DB_HOST', '127.0.0.1').':'.env('MYFIS_DB_PORT', '3307'),
            'database' => env('MYFIS_DB_USR', 'fims_usr'),
        ]);
    }

    /**
     * Check Desk365 API connection status.
     */
    public function desk365Status(): JsonResponse
    {
        if (! $this->desk365->isConfigured()) {
            return $this->sendOk([
                'configured' => false,
                'message' => 'DESK365_API_KEY not set in .env',
            ]);
        }

        $result = $this->desk365->ping();
        $ok = ! isset($result['error']);

        return $this->sendOk([
            'configured' => true,
            'connected' => $ok,
            'base_url' => config('services.desk365.base_url'),
            'response' => $ok ? $result : ['error' => $result['error'] ?? 'Unknown'],
        ]);
    }

    /**
     * Get latest tickets from Desk365.
     */
    public function desk365Tickets(Request $request): JsonResponse
    {
        if (! $this->desk365->isConfigured()) {
            return $this->sendError(400, 'BAD_REQUEST', 'DESK365_API_KEY not set in .env');
        }

        $limit = min((int) $request->input('limit', 20), 100);
        $tickets = $this->desk365->listLatestTickets($limit);

        if (isset($tickets['error'])) {
            return $this->sendError(502, 'UPSTREAM_ERROR', $tickets['error']);
        }

        return $this->sendOk($tickets, ['count' => count($tickets)]);
    }

    /**
     * Sync Desk365 tickets to AI Vector Store. Agent akan dapat akses ticket selepas sync.
     */
    public function syncDesk365Tickets(): JsonResponse
    {
        set_time_limit(180);
        $result = $this->ticketSync->syncFromDesk365('api');

        return $this->sendOk($result);
    }

    /**
     * Latest internal (Kerisi) support tickets — preview for Knowledge Base (same idea as desk365-tickets).
     */
    public function internalTicketsPreview(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 30), 100);
        $rows = SupportTicket::query()
            ->with(['requestor:id,name,email', 'assignee:id,name,email'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $data = $rows->map(fn (SupportTicket $t) => [
            'ticket_number' => $t->ticket_number,
            'subject' => $t->subject,
            'status' => $t->status,
            'priority' => $t->priority,
            'type' => $t->type,
            'module' => $t->module,
            'contact_name' => $t->requestor?->name,
            'company_name' => $t->customer_name,
            'system_name' => $t->system_name,
            'created_time' => $t->created_at?->toIso8601String(),
        ])->values()->all();

        return $this->sendOk($data, ['count' => count($data)]);
    }

    /**
     * Sync all internal support tickets to OpenAI Vector Store.
     */
    public function syncInternalTickets(): JsonResponse
    {
        set_time_limit(180);
        $result = $this->internalTicketSync->syncAll('api');

        return $this->sendOk($result);
    }

    /**
     * Extract full KERISI DB schema + FK relationships, then upload to AI Vector Store.
     */
    public function syncDatabaseSchema(): JsonResponse
    {
        return $this->runKnowledgeExtract('schema', 'Database schema and relationships synced to AI');
    }

    public function syncKnowledgeLookup(): JsonResponse
    {
        return $this->runKnowledgeExtract('lookup', 'Lookup and reference data synced to AI');
    }

    public function syncKnowledgeMenuAccess(): JsonResponse
    {
        return $this->runKnowledgeExtract('menu_access', 'Menu and access mapping synced to AI');
    }

    public function syncKnowledgePages(): JsonResponse
    {
        return $this->runKnowledgeExtract('pages', 'Page, component, item, and control structure synced to AI');
    }

    public function syncKnowledgeBl(): JsonResponse
    {
        return $this->runKnowledgeExtract('bl', 'Business logic (PHP/JS) and trigger mapping synced to AI');
    }

    public function knowledgeExtractSyncLogs(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user instanceof User || ! $this->canViewTicketAiSyncLogs($user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot view knowledge extract sync logs');
        }

        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);
        $section = $request->input('section');

        $query = KnowledgeExtractSyncLog::with('user')->orderBy('created_at', 'desc');
        if (is_string($section) && $section !== '') {
            $query->where('section', $section);
        }

        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->sendOk($rows, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    private function runKnowledgeExtract(string $section, string $successMessage): JsonResponse
    {
        set_time_limit(600);
        $userId = Auth::id();
        $output = '';

        try {
            $code = Artisan::call('kerisi:extract-knowledge', [
                '--section' => $section,
                '--upload' => true,
            ]);
            $output = trim(Artisan::output());

            if ($code !== 0) {
                KnowledgeExtractSyncLog::create([
                    'user_id' => $userId,
                    'section' => $section,
                    'triggered_by' => 'api',
                    'status' => 'failed',
                    'message' => 'Extract command exited with code '.$code,
                    'output' => Str::limit($output, 65000),
                ]);

                return $this->sendError(500, 'INTERNAL_ERROR', 'Knowledge extract failed', [
                    'exit_code' => $code,
                    'output' => Str::limit($output, 65000),
                ]);
            }

            KnowledgeExtractSyncLog::create([
                'user_id' => $userId,
                'section' => $section,
                'triggered_by' => 'api',
                'status' => 'success',
                'message' => $successMessage,
                'output' => Str::limit($output, 65000),
            ]);

            return $this->sendOk([
                'success' => true,
                'message' => $successMessage,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            KnowledgeExtractSyncLog::create([
                'user_id' => $userId,
                'section' => $section,
                'triggered_by' => 'api',
                'status' => 'failed',
                'message' => $e->getMessage(),
                'output' => Str::limit($output, 65000),
            ]);

            return $this->sendError(500, 'INTERNAL_ERROR', 'Failed to run knowledge extract', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Paginated Desk365 → AI sync history (for Desk365 ticket log UI).
     */
    public function desk365SyncLogs(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user instanceof User || ! $this->canViewTicketAiSyncLogs($user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot view Desk365 sync logs');
        }

        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);

        $query = Desk365SyncLog::with('user')->orderBy('created_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->sendOk($rows, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    /**
     * Paginated internal (Kerisi) ticket → AI sync history.
     */
    public function internalTicketSyncLogs(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user instanceof User || ! $this->canViewTicketAiSyncLogs($user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot view internal ticket sync logs');
        }

        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);

        $query = InternalTicketSyncLog::with('user')->orderBy('created_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->sendOk($rows, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $limit)),
        ]);
    }

    /**
     * Level 1–4 with ticket or knowledge access; super admin always allowed.
     */
    private function canViewTicketAiSyncLogs(User $user): bool
    {
        if (UserLevel::normalize($user->user_level ?? UserLevel::USER) === UserLevel::SUPER_ADMIN) {
            return true;
        }

        $level = UserLevel::normalize($user->user_level ?? UserLevel::USER);
        $allowedLevels = [
            UserLevel::INTERNAL_ADMIN,
            UserLevel::EXTERNAL_ADMIN,
            UserLevel::AGENT,
            UserLevel::USER,
            UserLevel::SECONDARY_USER,
        ];
        if (! in_array($level, $allowedLevels, true)) {
            return false;
        }

        return $user->hasPermission(Permission::KNOWLEDGE_VIEW)
            || $user->hasPermission(Permission::KNOWLEDGE_MANAGE)
            || $user->hasPermission(Permission::TICKETS_VIEW)
            || $user->hasPermission(Permission::TICKETS_CREATE)
            || $user->hasPermission(Permission::TICKETS_RESPOND)
            || $user->hasPermission(Permission::TICKETS_EDIT);
    }
}
