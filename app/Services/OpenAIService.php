<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $apiKey;

    private string $baseUrl = 'https://api.openai.com/v1';

    private string $assistantId;

    private string $vectorStoreId;

    private MyfisDbService $db;

    private ?string $userChatAssistantId = null;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key');
        $this->assistantId = config('services.openai.assistant_id', '');
        $this->userChatAssistantId = config('services.openai.user_chat_assistant_id') ?: null;
        $this->vectorStoreId = config('services.openai.vector_store_id', '');
        $this->db = new MyfisDbService;
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'OpenAI-Beta' => 'assistants=v2',
        ];
    }

    // ─── Vector Store ────────────────────────────────────────────────────────

    public function createVectorStore(string $name): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/vector_stores", [
                'name' => $name,
            ]);

        return $response->json();
    }

    public function uploadFileToVectorStore(string $filePath, string $filename): array
    {
        // Step 1: Upload file (timeout 5 min for large BL/schema files)
        $fileResponse = Http::timeout(300)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'OpenAI-Beta' => 'assistants=v2',
            ])->attach('file', file_get_contents($filePath), $filename)
            ->post("{$this->baseUrl}/files", [
                'purpose' => 'assistants',
            ]);

        $fileData = $fileResponse->json();

        if (! isset($fileData['id'])) {
            return ['error' => 'Failed to upload file', 'details' => $fileData];
        }

        // Step 2: Add file to vector store
        $vsResponse = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/vector_stores/{$this->vectorStoreId}/files", [
                'file_id' => $fileData['id'],
            ]);

        return [
            'file_id' => $fileData['id'],
            'vector_store' => $vsResponse->json(),
        ];
    }

    /**
     * Upload image file for vision (chat attachments). Returns ['file_id' => '...'] or ['error' => '...'].
     * Retries once on 5xx (transient OpenAI errors).
     */
    public function uploadFileForVision(string $filePath, string $filename): array
    {
        $upload = fn () => Http::timeout(90)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'OpenAI-Beta' => 'assistants=v2',
            ])->attach('file', file_get_contents($filePath), $filename)
            ->post("{$this->baseUrl}/files", ['purpose' => 'vision']);

        $response = $upload();
        if ($response->serverError()) {
            sleep(2);
            $response = $upload();
        }

        $data = $response->json();
        if (! isset($data['id'])) {
            $errMsg = $data['error']['message'] ?? $data['error']['code'] ?? $response->body();

            return ['error' => (string) $errMsg, 'details' => $data];
        }

        return ['file_id' => $data['id']];
    }

    /**
     * Upload multiple files to vector store in parallel (faster for RBAC, workflow, etc.).
     *
     * @param  array<int, array{path: string, filename: string}>  $items  [['path' => fullPath, 'filename' => name], ...]
     * @return array<int, array{path: string, filename: string, file_id?: string, error?: string}>
     */
    public function uploadFilesToVectorStoreBatch(array $items, int $concurrency = 5): array
    {
        if (empty($items)) {
            return [];
        }

        $uploadHeaders = [
            'Authorization' => "Bearer {$this->apiKey}",
            'OpenAI-Beta' => 'assistants=v2',
        ];

        $results = [];
        foreach (array_chunk($items, $concurrency) as $chunk) {
            // Step 1: Upload files in parallel
            $uploadResponses = Http::pool(fn (Pool $pool) => collect($chunk)->mapWithKeys(function ($item, $i) use ($pool, $uploadHeaders) {
                $key = $item['path'] ?? (string) $i;
                $content = file_get_contents($item['path']);

                return [
                    $key => $pool->as($key)
                        ->timeout(120)
                        ->withHeaders($uploadHeaders)
                        ->attach('file', $content, $item['filename'])
                        ->post("{$this->baseUrl}/files", ['purpose' => 'assistants']),
                ];
            })->all());

            // Step 2: Add to vector store in parallel (only for successful uploads)
            $toAdd = [];
            foreach ($chunk as $i => $item) {
                $key = $item['path'] ?? (string) $i;
                $resp = $uploadResponses[$key] ?? null;
                $fileData = $resp ? $resp->json() : null;
                if (isset($fileData['id'])) {
                    $toAdd[] = ['item' => $item, 'file_id' => $fileData['id']];
                } else {
                    $results[] = array_merge($item, [
                        'error' => $fileData['error']['message'] ?? json_encode($fileData ?? $resp?->body()),
                    ]);
                }
            }

            if (! empty($toAdd)) {
                Http::pool(fn (Pool $pool) => collect($toAdd)->mapWithKeys(function ($entry, $i) use ($pool) {
                    $key = $entry['item']['path'] ?? (string) $i;

                    return [
                        $key => $pool->as($key)
                            ->timeout(30)
                            ->withHeaders($this->headers())
                            ->post("{$this->baseUrl}/vector_stores/{$this->vectorStoreId}/files", [
                                'file_id' => $entry['file_id'],
                            ]),
                    ];
                })->all());

                foreach ($toAdd as $entry) {
                    $results[] = array_merge($entry['item'], ['file_id' => $entry['file_id']]);
                }
            }
        }

        return $results;
    }

    public function deleteFile(string $openaiFileId): bool
    {
        Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/vector_stores/{$this->vectorStoreId}/files/{$openaiFileId}");

        Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/files/{$openaiFileId}");

        return true;
    }

    /**
     * List one page of files attached to the configured vector store (Assistants API v2).
     *
     * @return array<string, mixed>
     */
    public function listVectorStoreFilesPage(?string $after = null, int $limit = 100): array
    {
        if ($this->vectorStoreId === '') {
            return ['error' => ['message' => 'OPENAI_VECTOR_STORE_ID is not set']];
        }

        $query = array_filter([
            'limit' => $limit,
            'after' => $after,
        ], fn ($v) => $v !== null && $v !== '');

        $response = Http::timeout(120)
            ->withHeaders($this->headers())
            ->get("{$this->baseUrl}/vector_stores/{$this->vectorStoreId}/files", $query);

        return $response->json();
    }

    /**
     * List all files in the vector store (paginated).
     *
     * @return array{data?: array<int, array<string, mixed>>, error?: array}
     */
    public function listAllVectorStoreFiles(int $limitPerPage = 100, int $maxPages = 200): array
    {
        if ($this->vectorStoreId === '') {
            return ['error' => ['message' => 'OPENAI_VECTOR_STORE_ID is not set']];
        }

        $all = [];
        $after = null;
        $pageNum = 0;

        do {
            $pageNum++;
            if ($pageNum > $maxPages) {
                break;
            }
            $page = $this->listVectorStoreFilesPage($after, $limitPerPage);
            if (isset($page['error'])) {
                return $page;
            }
            foreach ($page['data'] ?? [] as $row) {
                $all[] = $row;
            }
            $hasMore = (bool) ($page['has_more'] ?? false);
            $after = $page['last_id'] ?? null;
            if (! $hasMore || $after === null || $after === '') {
                break;
            }
        } while (true);

        return ['data' => $all];
    }

    /**
     * File metadata (filename, bytes, purpose) — GET /v1/files/{file_id}.
     *
     * @return array<string, mixed>
     */
    public function retrieveFileMetadata(string $fileId): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'OpenAI-Beta' => 'assistants=v2',
            ])->get("{$this->baseUrl}/files/{$fileId}");

        return $response->json();
    }

    /**
     * Raw file bytes — GET /v1/files/{file_id}/content.
     *
     * @return array{ok: bool, body?: string, status?: int, error?: string}
     */
    public function downloadFileContent(string $fileId): array
    {
        $response = Http::timeout(300)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])
            ->get("{$this->baseUrl}/files/{$fileId}/content");

        if (! $response->successful()) {
            return [
                'ok' => false,
                'status' => $response->status(),
                'error' => $response->body(),
            ];
        }

        return [
            'ok' => true,
            'body' => $response->body(),
        ];
    }

    // ─── Assistant ───────────────────────────────────────────────────────────

    public function createAssistant(): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/assistants", [
                'name' => 'SELAR Support AI',
                'instructions' => $this->systemPrompt(),
                'model' => 'gpt-4o',
                'tools' => $this->assistantTools(),
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$this->vectorStoreId],
                    ],
                ],
            ]);

        return $response->json();
    }

    public function updateAssistant(string $assistantId): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/assistants/{$assistantId}", [
                'instructions' => $this->systemPrompt(),
                'tools' => $this->assistantTools(),
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$this->vectorStoreId],
                    ],
                ],
            ]);

        return $response->json();
    }

    // ─── Chat / Thread ───────────────────────────────────────────────────────

    public function createThread(): array
    {
        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->post("{$this->baseUrl}/threads", []);

        $data = $response->json();
        if ($response->failed()) {
            $errMsg = $data['error']['message'] ?? $data['error']['code'] ?? $response->body();
            Log::warning('OpenAI createThread failed', ['status' => $response->status(), 'body' => $data]);

            return ['error' => "OpenAI API: {$errMsg}"];
        }

        return $data;
    }

    /**
     * @param  array<string>  $imageFileIds  OpenAI file IDs for vision (images)
     * @param  string|null  $documentText  Extracted text from PDF/DOCX/Excel to prepend
     * @param  bool  $isUserChat  When true, uses User Chat assistant (no SQL/schema tools; KB only)
     */
    public function sendMessage(string $threadId, string $message, array $imageFileIds = [], ?string $documentText = null, bool $isUserChat = false): array
    {
        // Cancel any active runs so we can add a new message (fixes "Can't add messages to thread while a run is active")
        $this->cancelActiveRuns($threadId);

        $textContent = $message;
        if ($documentText) {
            $textContent = "[Dokumen yang dilampirkan:\n\n{$documentText}]\n\n---\n\nSoalan pengguna: {$message}";
        }

        $content = $this->buildMessageContent($textContent, $imageFileIds);

        // Add user message to thread (retry once on 5xx)
        $postMessage = fn () => Http::timeout(90)
            ->withHeaders($this->headers())
            ->post("{$this->baseUrl}/threads/{$threadId}/messages", [
                'role' => 'user',
                'content' => $content,
            ]);

        $msgResponse = $postMessage();
        if ($msgResponse->serverError()) {
            sleep(2);
            $msgResponse = $postMessage();
        }

        if ($msgResponse->failed()) {
            $body = $msgResponse->json();
            $err = $body['error'] ?? [];
            $code = $err['code'] ?? '';
            $msg = $err['message'] ?? $err['code'] ?? $msgResponse->body();
            $errMsg = $code ? "{$code}|{$msg}" : "OpenAI API: {$msg}";
            Log::warning('OpenAI add message failed', ['status' => $msgResponse->status(), 'body' => $body]);

            return ['error' => $errMsg];
        }

        $assistantId = ($isUserChat && $this->userChatAssistantId) ? $this->userChatAssistantId : $this->assistantId;
        if ($isUserChat && ! $this->userChatAssistantId) {
            Log::warning('OpenAI: OPENAI_USER_CHAT_ASSISTANT_ID not set; using Support Chat assistant for User Chat (SQL/schema tools still available)');
        }

        // Run the assistant
        $runResponse = Http::timeout(30)
            ->withHeaders($this->headers())
            ->post("{$this->baseUrl}/threads/{$threadId}/runs", [
                'assistant_id' => $assistantId,
            ]);

        $run = $runResponse->json();
        if ($runResponse->failed()) {
            $err = $run['error'] ?? [];
            $code = $err['code'] ?? '';
            $msg = $err['message'] ?? $err['code'] ?? $runResponse->body();
            $errMsg = $code ? "{$code}|{$msg}" : "OpenAI API: {$msg}";
            Log::warning('OpenAI create run failed', ['status' => $runResponse->status(), 'body' => $run]);

            return ['error' => $errMsg];
        }

        $runId = $run['id'] ?? null;
        if (! $runId) {
            return ['error' => 'Failed to create run', 'details' => $run];
        }

        // Poll loop — handles both normal completion and tool_calls
        $maxAttempts = 60;
        $attempt = 0;
        $runData = $run;

        do {
            sleep(1);
            $statusResponse = Http::withHeaders($this->headers())
                ->get("{$this->baseUrl}/threads/{$threadId}/runs/{$runId}");
            $runData = $statusResponse->json();
            $status = $runData['status'] ?? 'failed';
            $attempt++;

            if ($status === 'requires_action') {
                $toolOutputs = $this->handleToolCalls($runData);

                Http::withHeaders($this->headers())
                    ->post("{$this->baseUrl}/threads/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $toolOutputs,
                    ]);

                // Reset so we keep polling
                $status = 'in_progress';
            }
        } while (in_array($status, ['queued', 'in_progress']) && $attempt < $maxAttempts);

        if ($status !== 'completed') {
            $lastErr = $runData['last_error'] ?? [];
            $code = $lastErr['code'] ?? '';
            $message = $lastErr['message'] ?? $lastErr['code'] ?? "Run ended with status: {$status}";
            $errMsg = $code ? "{$code}|{$message}" : $message;
            Log::warning('OpenAI run not completed', ['status' => $status, 'last_error' => $lastErr]);

            return ['error' => $errMsg];
        }

        // Get latest assistant message
        $messagesResponse = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/threads/{$threadId}/messages", [
                'order' => 'desc',
                'limit' => 1,
            ]);

        $messages = $messagesResponse->json();
        $latest = $messages['data'][0] ?? null;

        if (! $latest || $latest['role'] !== 'assistant') {
            return ['error' => 'No assistant response found'];
        }

        return $this->parseMessage($latest);
    }

    private function buildMessageContent(string $text, array $imageFileIds): array|string
    {
        if (empty($imageFileIds)) {
            return $text;
        }

        $blocks = [['type' => 'text', 'text' => $text]];
        foreach (array_slice($imageFileIds, 0, 3) as $fileId) {
            $blocks[] = ['type' => 'image_file', 'image_file' => ['file_id' => $fileId, 'detail' => 'low']];
        }

        return $blocks;
    }

    /**
     * Cancel any queued or in_progress runs so we can add a new message.
     * Fixes "Can't add messages to thread while a run is active" (e.g. from timeout or double-send).
     */
    private function cancelActiveRuns(string $threadId): void
    {
        $runsResponse = Http::timeout(10)
            ->withHeaders($this->headers())
            ->get("{$this->baseUrl}/threads/{$threadId}/runs", ['limit' => 10]);

        if ($runsResponse->failed()) {
            return;
        }

        $data = $runsResponse->json();
        $runs = $data['data'] ?? [];

        foreach ($runs as $run) {
            $status = $run['status'] ?? '';
            if (in_array($status, ['queued', 'in_progress'])) {
                Http::timeout(10)
                    ->withHeaders($this->headers())
                    ->post("{$this->baseUrl}/threads/{$threadId}/runs/{$run['id']}/cancel");
            }
        }
    }

    // ─── Tool Call Handler ───────────────────────────────────────────────────

    private function handleToolCalls(array $runData): array
    {
        $toolCalls = $runData['required_action']['submit_tool_outputs']['tool_calls'] ?? [];
        $outputs = [];

        foreach ($toolCalls as $call) {
            $name = $call['function']['name'] ?? '';
            $args = json_decode($call['function']['arguments'] ?? '{}', true);
            $callId = $call['id'];

            $output = match ($name) {
                'query_kerisi_database' => $this->toolQueryDatabase($args),
                'get_table_schema' => $this->toolGetSchema($args),
                'find_tables' => $this->toolFindTables($args),
                default => ['error' => "Unknown tool: {$name}"],
            };

            $outputs[] = [
                'tool_call_id' => $callId,
                'output' => json_encode($output, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $outputs;
    }

    private function toolQueryDatabase(array $args): array
    {
        $sql = $args['sql'] ?? '';
        if (! $sql) {
            return ['error' => 'SQL query is required'];
        }

        Log::info('KERISI AI DB query', ['sql' => $sql]);

        return $this->db->query($sql, 100);
    }

    private function toolGetSchema(array $args): array
    {
        $table = $args['table'] ?? '';
        if (! $table) {
            return ['error' => 'Table name is required'];
        }

        return ['columns' => $this->db->describeTable($table)];
    }

    private function toolFindTables(array $args): array
    {
        $keyword = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($args['keyword'] ?? ''));
        if (! $keyword) {
            return ['error' => 'Keyword is required'];
        }

        $result = $this->db->query(
            'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES '.
            "WHERE TABLE_SCHEMA = 'fims_usr' AND TABLE_NAME LIKE '%{$keyword}%' ".
            'ORDER BY TABLE_NAME LIMIT 30',
            30
        );

        if (isset($result['error'])) {
            return $result;
        }

        return ['tables' => array_column($result['rows'] ?? [], 'TABLE_NAME'), 'rows' => $result['rows'] ?? []];
    }

    public function getThreadMessages(string $threadId): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/threads/{$threadId}/messages", [
                'order' => 'asc',
                'limit' => 100,
            ]);

        $messages = $response->json()['data'] ?? [];

        return array_map(fn ($msg) => $this->parseMessage($msg), $messages);
    }

    /**
     * Chat Completions API with vision (base64 images). Fallback when Assistants API fails with images.
     * Returns ['content' => '...', 'citations' => []] or ['error' => '...'].
     */
    public function chatWithVision(string $userMessage, ?string $documentText, array $imageBase64Urls): array
    {
        if (empty($imageBase64Urls)) {
            return ['error' => 'No images provided'];
        }

        $textContent = $userMessage;
        if ($documentText) {
            $textContent = "[Dokumen yang dilampirkan:\n\n{$documentText}]\n\n---\n\nSoalan pengguna: {$userMessage}";
        }

        $content = [['type' => 'text', 'text' => $textContent]];
        foreach (array_slice($imageBase64Urls, 0, 3) as $url) {
            if (str_starts_with($url, 'data:')) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'low']];
            }
        }

        $response = Http::timeout(90)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o',
                'max_tokens' => 4096,
                'messages' => [
                    ['role' => 'system', 'content' => $this->visionSystemPrompt()],
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

        $data = $response->json();
        if ($response->failed()) {
            $errMsg = $data['error']['message'] ?? $data['error']['code'] ?? $response->body();

            return ['error' => "OpenAI API: {$errMsg}"];
        }

        $choice = $data['choices'][0] ?? null;
        $text = $choice['message']['content'] ?? '';

        return ['content' => trim($text), 'citations' => []];
    }

    /**
     * First-line AI analysis for internal support tickets (Chat Completions, no Assistants thread).
     *
     * @return array{content: string}|array{error: string}
     */
    public function generateTicketAINAAssistantReply(string $transcript, string $ticketSubject, ?string $module, ?string $systemName): array
    {
        if (! is_string($this->apiKey) || trim($this->apiKey) === '') {
            return ['error' => 'OpenAI API key not configured'];
        }

        $sys = <<<'SYS'
You are **AINA**, the first-line AI helper inside **KERISI HelpDesk & SELAR/AINA (KEHSA)** internal ticketing.
Analyse the user's issue from the ticket thread. Give practical hints, checks, and next steps (procedural troubleshooting) in the **same language** as the user (Bahasa Malaysia or English).

Rules:
- You are NOT a human agent. Your analysis may be **wrong**, especially for complex business logic (BL), financial rules, or customer-specific setup.
- You do **not** have live database access here — do not claim you ran queries or saw live data.
- Use short headings and bullet points. Be helpful but cautious.

Do **not** add a closing “are you satisfied?” question — the application adds that separately.
SYS;

        $meta = 'Ticket subject: '.$ticketSubject."\nModule: ".($module ?: '(none)')."\nSystem: ".($systemName ?: '(none)')."\n\nThread (public messages; chronological):\n";

        $response = Http::timeout(120)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 2048,
                'messages' => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $meta.$transcript],
                ],
            ]);

        $data = $response->json();
        if ($response->failed()) {
            $errMsg = $data['error']['message'] ?? $data['error']['code'] ?? $response->body();

            return ['error' => 'OpenAI API: '.(string) $errMsg];
        }

        $text = $data['choices'][0]['message']['content'] ?? '';

        return ['content' => trim((string) $text)];
    }

    /**
     * Ringkasan / draf balasan untuk ejen sokongan (bukan mesej AINA kepada pemohon).
     *
     * @return array{content: string}|array{error: string}
     */
    public function generateAgentAssistReplySuggestion(
        string $transcript,
        string $ticketSubject,
        ?string $module,
        ?string $systemName,
        ?string $assigneeName,
        ?string $regenerateHint,
    ): array {
        if (! is_string($this->apiKey) || trim($this->apiKey) === '') {
            return ['error' => 'OpenAI API key not configured'];
        }

        $sys = <<<'SYS'
You are a **support team assistant** for internal staff replying in **KERISI / KEHSA** ticketing.

Your task: produce a **short draft reply** the agent can send to the **customer/requestor** (concise summary + clear next steps).

Rules:
- Match the customer's language (Bahasa Malaysia or English) as in the thread.
- Be professional, polite, and practical. Use short paragraphs or bullet points. You may use light Markdown (bold, lists).
- You do **not** have live database access — no fabricated query results. If unsure, say the agent should verify in the system.
- This is **internal assistance only** — do not mimic AINA's auto-reply footer or satisfaction survey wording.
- Keep the draft roughly **120–400 words** unless the issue is trivial (then shorter).
- Do **not** sign with a fake name; optional closing like "Sekian, terima kasih" is fine without a signature block.
SYS;

        $meta = 'Ticket subject: '.$ticketSubject."\n"
            .'Module: '.($module ?: '(none)')."\n"
            .'System: '.($systemName ?: '(none)')."\n"
            .'Assigned agent (for context): '.($assigneeName ?: '(none)')."\n\n"
            ."Thread (public messages only; chronological):\n";

        $userBody = $meta.$transcript;
        if (is_string($regenerateHint) && trim($regenerateHint) !== '') {
            $userBody .= "\n\n---\n**Revision request from agent:**\n".trim($regenerateHint);
        }

        $response = Http::timeout(90)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 1200,
                'messages' => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $userBody],
                ],
            ]);

        $data = $response->json();
        if ($response->failed()) {
            $errMsg = $data['error']['message'] ?? $data['error']['code'] ?? $response->body();

            return ['error' => 'OpenAI API: '.(string) $errMsg];
        }

        $text = $data['choices'][0]['message']['content'] ?? '';

        return ['content' => trim((string) $text)];
    }

    private function visionSystemPrompt(): string
    {
        return 'You are **SELAR** (Support AI within the AFSA admin suite). Analyze the image(s) and answer in the same language the user uses (Bahasa Malaysia or English). '
            .'STANDARD SAMA: Every ticket solution MUST have the same format — 4 sections (Diagnostik, Punca, Pencegahan, Pembetulan) + SQL in code blocks + BL names. No shorter answers for modules without examples. '
            .'If the image shows a support ticket and the user asks "penyelesaian", "solution", "mcmana nk selesaikan", "bagaimana selesaikan" — you MUST give a FULL technical solution with SQL, BL names, and 4 sections. NEVER give generic advice like "Pengesahan data", "Pemastautinian sistem", or "Rujuk pihak pembangunan". '
            .'If user asks "extract ticket paling kritikal" or "ticket paling kritikal untuk [modul]" — prioritise impak perniagaan: balance tak tally, data missing, duplikat posting. For Project Monitoring: FA Trigger/BRN tak appear di Expenses (bukan Structure Budget duplicate). For Petty Cash: DATA MISSING PADA DETAIL TRANSACTION. Rujuk kerisi-ticket-resolution-guide. '
            .'Format wajib: (1) Diagnostik — SQL dalam code block, (2) Punca Berkemungkinan — rujuk BL, (3) Pencegahan, (4) Pembetulan — dengan "Sahkan sebelum jalankan". '
            .'Infer the module from the ticket (AP→bills/voucher, Petty Cash→petty_cash_*, Investment→investment_*, Budget→budget_*, GL→posting_*, Payroll→payroll_*, etc.). '
            .'For ANY module: include diagnostic SQL (SELECT from relevant tables), state BL names from the module, and corrective SQL with "Sahkan sebelum jalankan". Be technical and specific, not procedural. Apply this to ALL modules: AP, AR, Advance, Asset, Budget, Cashbook, Closing, Credit Control, GL, Investment, Loan, Overtime, Payroll, Petty Cash, Project Monitoring, Purchasing, Student Finance, Vendor, etc.';
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function parseMessage(array $message): array
    {
        $content = '';
        $citations = [];

        foreach ($message['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text = $block['text']['value'] ?? '';
                $annotations = $block['text']['annotations'] ?? [];

                foreach ($annotations as $annotation) {
                    if ($annotation['type'] === 'file_citation') {
                        $citations[] = $annotation['file_citation']['file_id'] ?? '';
                        $text = str_replace($annotation['text'], '', $text);
                    }
                }

                $content .= $text;
            }
        }

        return [
            'role' => $message['role'],
            'content' => trim($content),
            'citations' => array_unique($citations),
        ];
    }

    private function assistantTools(): array
    {
        return [
            ['type' => 'file_search'],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'query_kerisi_database',
                    'description' => 'Execute a READ-ONLY SQL SELECT query on the KERISI (MYFIS) live database to retrieve real data. Use this when the user asks about actual data, balances, transactions, users, settings, or any live information that cannot be found in documentation. Only SELECT queries are allowed.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'sql' => [
                                'type' => 'string',
                                'description' => 'A valid MySQL SELECT statement. Must not contain INSERT, UPDATE, DELETE, DROP, ALTER, or any mutation. Add LIMIT to avoid large results. Table names are in fims_usr schema.',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Brief explanation of why this query is needed to answer the user question.',
                            ],
                        ],
                        'required' => ['sql', 'reason'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_table_schema',
                    'description' => 'Get the column definitions for a specific database table in KERISI. Use this when you need to understand table structure before writing a query.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'table' => [
                                'type' => 'string',
                                'description' => 'The exact table name to describe.',
                            ],
                        ],
                        'required' => ['table'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'find_tables',
                    'description' => 'Find table names in fims_usr matching a keyword. Call this FIRST when: (1) user asks for data (jumlah, baki, senarai), (2) user asks for ticket resolution/penyelesaian — to find tables for ANY module. Use keyword from ticket: petty_cash, bills, voucher, budget, investment, payroll, etc. Returns list of matching tables.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => [
                                'type' => 'string',
                                'description' => 'Search keyword e.g. po, budget, voucher, accrual, investment, store, requisition.',
                            ],
                        ],
                        'required' => ['keyword'],
                    ],
                ],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        $baseUrl = rtrim(config('services.kerisi.system_url', 'http://myfisv2-tourism.datasc.dev'), '/');

        return <<<PROMPT
You are **SELAR** (*Smart Efficient Lively AI Reference*) — a helpful bilingual expert assistant for **support staff and agents** using the **KERISI** (formerly MYFIS) accounting and government financial system (e.g. Tourism Malaysia and other agencies). The fish-themed name “SELAR” is intentional, like “KERISI” for the product.

You operate inside the **AFSA** admin suite (*Admin for SELAR & AINA*). In conversation your identity is **SELAR** only — do not introduce yourself as “AFSA”.

## Identity & greeting (Support Chat)
- Your public name is **SELAR**. When the user sends their **first message** in a new conversation (or starts a new chat), begin with a **short friendly greeting** (1–2 sentences): introduce yourself as SELAR, mention you help with KERISI/MYFIS support, and invite them to ask. Use **Bahasa Malaysia** or **English** to match the user. **Do not** repeat this full greeting on every follow-up in the same thread.

You serve three purposes:
1. **Procedure & How-To**: Answer questions about how to use the system based on documentation (User Manuals, BRS, Walkthrough guides, support tickets).
2. **Database Structure**: When users ask which tables are involved in a process, module, or feature — query the database to find and explain them.
3. **Live Data Queries**: When users ask about actual data (balances, transactions, lists, statuses) — query the live database and return results.

## CRITICAL RULES
- ALWAYS use `query_kerisi_database` for ANY question about tables, data, database structure, OR menu/navigation. NEVER guess.
- **TICKET RESOLUTION:** When user asks "penyelesaian", "solution", "mcmana nk selesaikan" — you MUST call `find_tables` and `get_table_schema` FIRST, then output SQL (diagnostic + corrective), BL names, and structured 4-section solution. NEVER give generic "Pengesahan data / Pemastautinian sistem" without SQL.
- Answer in the same language the user uses (Bahasa Malaysia or English)
- Present results clearly: use tables, bullet points, numbered lists, code blocks for SQL
- If the first query gives limited results, run follow-up queries to get complete information
- Never expose raw SQL to the user unless they explicitly ask for it — **EXCEPTION:** Ticket resolution ALWAYS includes SQL (diagnostic + corrective)
- **ALWAYS include the direct URL** when answering any question about menus, screens, or navigation

## Ticket Resolution — Penyelesaian Ticket (MANDATORY — SEMUA MODUL)

**STANDARD SAMA:** Setiap jawapan penyelesaian ticket MESTI sama format dan kualiti — tidak kira modul. AP, Petty Cash, Project Monitoring, Budget, AP — SEMUA dapat 4 bahagian + SQL + BL. Jangan beri jawapan lebih pendek atau generik untuk modul yang tiada contoh. Guna tools (find_tables, get_table_schema) untuk bina penyelesaian penuh.

When user asks **penyelesaian**, **solution**, **mcmana nk selesaikan**, **bagaimana selesaikan** for ANY ticket from ANY module — you MUST output a FULL technical solution with SQL, BL names, and diagnostics. NEVER give generic advice. This applies to ALL modules: AP, AR, Budget, Cashbook, Investment, Petty Cash, Payroll, Purchasing, Asset, Loan, Student Finance, etc.

**FORBIDDEN — Jangan keluarkan jawapan seperti ini:**
- "Pengesahan data", "Pemastautinian sistem", "Pembaikan dan ujian" — TERLALU GENERIK
- "Rujuk pihak pembangunan" / "tiada dalam dokumentasi" — ANDA ADA AKSES DB + BL
- Nasihat tinggi tanpa SQL konkrit — USER PERLU SQL UNTUK JALANKAN

**MANDATORY — MESTI buat dalam urutan:**
1. **Search knowledge base** — Cari "kerisi-ticket-resolution-guide" atau "kerisi-tickets-[modul]" untuk contoh penyelesaian serupa
2. **Panggil tools** — `find_tables(keyword)` dengan keyword dari jadual di bawah mengikut modul ticket
3. **get_table_schema** — Untuk table utama sebelum tulis SQL
4. **Keluarkan SQL** — Dalam code block, bukan hanya sebut "run query"
5. **Format jawapan MESTI ada 4 bahagian:**

```
## 1. Diagnostik (SQL)
[Code block dengan SELECT queries untuk semak data]

## 2. Punca Berkemungkinan
- [Punca A] — rujuk BL X, column Y
- [Punca B] — ...

## 3. Pencegahan
- Perubahan dalam BL [nama]: [apa yang perlu check/tambah]
- Unique constraint: [SQL ALTER TABLE jika sesuai]

## 4. Pembetulan
[Code block dengan UPDATE/DELETE — tambah "Sahkan sebelum jalankan"]
```

**Modul → find_tables keyword (guna untuk SEMUA ticket):**
| Modul | keyword |
|-------|---------|
| AP / Bil / Baucar | bills, voucher, payment |
| AR / Penghutang | cust_invoice, ar_invoice |
| Advance / Imbuhan | advance |
| Asset / Harta | asset |
| Budget / Bajet | budget, virement |
| Cashbook / Buku tunai | cashbook, receipt |
| Closing | closing |
| Credit Control | credit_control |
| GL / Lejar am | posting, journal |
| Investment | investment, accrual |
| Loan / Pinjaman | loan |
| Overtime | overtime, payroll |
| Payroll / Gaji | payroll, staff_salary |
| Petty Cash / Panjar | petty_cash, panjar |
| Project Monitoring | project, budget, capital |
| Purchasing / PO | po, purchase, requisition, store |
| Student Finance | student |
| Vendor | vendor, vend_ |

**Ekstrak modul dari ticket:** Tajuk ticket atau masalah (e.g. "panjar"→petty_cash, "baucar"→voucher, "gaji"→payroll). Jika modul tidak jelas, cuba 2-3 keyword berkaitan.

**Modul tanpa contoh dalam guide:** Tetap keluarkan 4 bahagian penuh. Panggil find_tables(keyword) → get_table_schema(table) → tulis SQL diagnostik berdasarkan column yang ada. Nyatakan BL dari kerisi-tickets-[modul] atau kerisi-workflow-[modul]. Jangan pendekkan jawapan.

## Extract Ticket Paling Kritikal — MESTI Rujuk Ticket Resolution Guide
When user asks **"extract ticket paling kritikal"**, **"ticket paling kritikal untuk [modul]"**, **"most critical ticket"** — you MUST:
1. **Search kerisi-ticket-resolution-guide** FIRST — ia ada contoh ticket yang telah dianalisis dengan impak perniagaan
2. **Jangan hanya** pilih ticket yang bertanda "Big" dalam kerisi-tickets-[modul].md — pertimbangkan impak: balance tak tally, data missing, duplikat posting lebih kritikal daripada isu struktur/setup
3. **Kriteria impak tinggi (semua modul):**
   - **AP:** Bill/voucher tidak submit, Payee Code/Name tidak keluar, EFT preparation gagal
   - **AR:** Aging tidak tepat, invoice tidak link
   - **Budget:** Virement Double Record, Expenses Budget Details vs Expenses tak sama, baki negatif
   - **Cashbook:** Cash book xsama lejar, bank reconcile tak match, jurnal tidak masuk ke cash book
   - **GL:** BRN/WPN/GRN Double Posting, BRN tak posting di GL, Bill reject masih dalam ledger
   - **Investment:** No FD berbeza, 9 jurnal sijil sama (duplikat accrual)
   - **Petty Cash:** DATA MISSING PADA DETAIL TRANSACTION (transaksi tiada dalam jurnal)
   - **Project Monitoring:** FA Trigger tak tally, BRN tak appear di Expenses (balance discrepancy) — BUKAN Structure Budget duplicate
   - **Purchasing:** DOUBLE PRE, WPN tidak link dengan bill, PO Confirmation tiada Budget ID
   - **Advance/Loan/Asset/Payroll/Student Finance/Closing:** Balance tidak tally, data missing, duplikat
   - **Credit Control:** Aging debtor tidak tepat, limit melebihi
   - **Overtime:** OT tidak masuk payroll, amount salah
5. Keluarkan butiran ticket + pautan ke penyelesaian teknikal (SQL, BL) dari guide

## How to Find Tables for a Module
When user asks "table mana terlibat dengan [module]?" or "which tables are used for [feature]?", run:
```sql
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'fims_usr'
AND TABLE_NAME LIKE '%keyword%'
ORDER BY TABLE_NAME
```
Use multiple keywords. For AP: `bills`, `voucher`, `payment`, `creditor`, `invoice`, `ap_`
For Budget: `budget`, `virement`, `allocation`
For GL: `posting`, `journal`, `ledger`, `gl_`
For Payroll: `payroll`, `staff_salary`, `staff_deduction`
For Cashbook: `cashbook`, `receipt`, `bank`
For PO/Purchasing: `po`, `purchase`, `store`, `requisition`, `order`

## How to Get Table Structure
When user asks about columns or fields of a table, run:
```sql
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'fims_usr' AND TABLE_NAME = 'table_name'
ORDER BY ORDINAL_POSITION
```

## Key Table Naming Conventions in fims_usr
- **AP (Account Payable)**: `bills_master`, `bills_details`, `voucher_master`, `voucher_details`, `payment_batch`, `payment_record`, `credit_note_ap_*`, `checklist_ap_*`
- **AR (Account Receivable)**: `cust_invoice_*`, `ar_invoice_*`, `invoice_ageing`, `creditor_knockoff`
- **Budget**: `budget_*` tables (24 tables)
- **GL (General Ledger)**: `posting_*`, `gl_*`, `mapping_acct_*`
- **Payroll**: `payroll_*`, `staff_*` tables
- **Asset**: `asset_*` tables
- **Purchasing / PO**: `requisition_*`, `store_*`, `po_*`, `purchase_*` — PO = Purchase Order
- **Vendor**: `vendor_address`, `vendor_jobscope`, `vend_*`
- **Loan**: `loan_application`, `loan_payment_schedule`

## Live Data Queries — MANDATORY: User Boleh Tanya Data Langsung
NEVER say "saya tidak tahu nama table" or "perlu dokumentasi". You MUST query the database.

**Workflow when user asks for data (jumlah, baki, senarai):**
1. If table name unknown → call `find_tables` with keyword (e.g. "po", "budget", "voucher", "store")
2. Pick the most relevant table from the list
3. Call `get_table_schema` if you need column names
4. Call `query_kerisi_database` with your SELECT query

**Examples:**
- "Berapa jumlah PO bulan ni?" → find_tables("po") or find_tables("store") → get tables → SELECT COUNT(*) FROM [table] WHERE MONTH(date_col)=...
- "Baki budget PTJ kewangan" → find_tables("budget") → get schema for budget table → query with PTJ filter
- "Senarai bil belum bayar" → query bills_master
- "Berapa banyak voucher?" → find_tables("voucher") → SELECT COUNT(*)

## Status Field Values (common across AP)
- `bim_status` in bills_master: `PENDING`, `APPROVE`, `ENDORSE`, `CANCEL`, `PAID`
- `vma_vch_status` in voucher_master: `PENDING`, `APPROVE`, `CANCEL`, `PAID`
- `pre_status` in payment_record: `PENDING`, `APPROVE`, `CANCEL`, `PAID`, `STOP`
- `pyb_status` in payment_batch: `PENDING`, `TRANSFER`, `CANCEL`

## Cancellation Fields (for "batalkan" questions)
When user asks about cancelling any AP/voucher/payment record, the cancel-related fields are:
- `*_cancel_by` — who cancelled
- `*_cancel_date` — when cancelled
- `*_cancel_reason` — reason for cancellation
- `pmt_posting_no_cancel` — GL reversal posting number

## Menu Navigation — MANDATORY: ALWAYS Query + Provide Direct URL
When user asks about navigation, menus, or "where to find" anything — this is MANDATORY:
1. FIRST call `query_kerisi_database` to search `fims.FLC_MENU` for the menu MENUID
2. THEN build and include the direct URL in your answer
3. NEVER answer navigation questions without first querying the menu database

**IMPORTANT: Menu names in FLC_MENU are in English or camelCase, NOT Malay.**
Translate keywords before searching. Examples:
- "tambahan bajet" → search `budget`, `increment`, `virement`
- "baucar bayaran" → search `voucher`, `payment`
- "bil pembekal" → search `bill`, `supplier`, `AP`
- "lejar am" → search `GL`, `ledger`, `journal`
- "gaji" → search `payroll`, `salary`
- "perolehan" → search `purchasing`, `requisition`, `procurement`
- "harta" → search `asset`
- "buku tunai" → search `cashbook`

**Query to find menu:**
```sql
SELECT m.MENUID, m.MENUNAME, m.MENULINK, m.MENULEVEL,
       (SELECT MENUNAME FROM fims.FLC_MENU WHERE MENUID = m.MENUPARENT) as PARENT_NAME,
       (SELECT MENUNAME FROM fims.FLC_MENU WHERE MENUID = (SELECT MENUPARENT FROM fims.FLC_MENU WHERE MENUID = m.MENUPARENT)) as GRANDPARENT_NAME
FROM fims.FLC_MENU m
WHERE (m.MENUNAME LIKE '%keyword1%' OR m.MENUNAME LIKE '%keyword2%')
AND m.MENUSTATUS = '1'
ORDER BY m.MENULEVEL, m.MENUORDER
LIMIT 15
```

**How to build the URL:**
- Base system URL: `{$baseUrl}`
- For `page_wrapper` menus: `{$baseUrl}/index.php?page=page_wrapper&menuid={MENUID}`
- For specific page menus (MENULINK != `index.php?page=page_wrapper`): `{$baseUrl}/{MENULINK}`

**Response format for navigation questions:**
```
📍 Laluan Menu: Grandparent > Parent > Menu Name
🔗 URL Terus: {$baseUrl}/index.php?page=page_wrapper&menuid=XXXX
```

## Database Context
Two MySQL schemas available:
- **`fims_usr`** — Live transactional data (1183 tables). Default schema. Use table names directly.
- **`fims`** — System metadata. Use prefix `fims.` e.g. `fims.FLC_MENU`, `fims.FLC_PAGE`, `fims.FLC_BL`

## System Architecture
- Navigation: `fims.FLC_MENU` → `fims.FLC_PAGE` → `fims.FLC_PAGE_COMPONENT` → `fims.FLC_PAGE_COMPONENT_ITEMS`
- Business Logic: `fims.FLC_BL` (PHP backend + JS frontend) triggered by `fims.FLC_TRIGGER`
- **Workflow**: Each page has a flow — controls (buttons) → triggers → BL. Use Workflow docs when users ask "apa flow", "apa terjadi bila klik Save", "urutan butang", etc.
- **RBAC**: Role-Based Access Control — which groups can access which menus/modules. Use RBAC docs when users ask "sapa boleh akses modul X?", "user dalam group Y boleh guna apa?", "kenapa tak nampak menu Z?".
- Support ticket history available for all common issues

## Diagrams / ERD — Use PlantUML (MANDATORY)
When user asks for **ERD**, **diagram**, **gambaran hubungan**, **bagi erd**, **bg erd**, **visual** of tables or process flow — you MUST output PlantUML code in a fenced block, NOT ASCII art. The system will render it as an image.

**Output format:**
```plantuml
@startuml
skinparam linetype ortho
entity "table_name" as t1 {
  * id : int
  --
  col1 : varchar
  col2 : date
}
entity "other_table" as t2 { ... }
t1 ||--o{ t2 : "relationship"
@enduml
```

**Rules:**
- Use `entity` for tables. Syntax: `entity "label" as alias { ... }`
- List key columns inside `{ }` with `*` for PK
- Use `||--o{` (one-to-many), `||--||` (one-to-one), etc. for relationships
- For flow diagrams, use `@startflowchart` or `@startuml` with `rectangle`, `->`
- ALWAYS wrap in ```plantuml ... ``` (or ```puml ... ```)
- For process/list flows: `rectangle "Step"`, `rectangle "Next"`, arrow `->`

## Technical Support — Business Logic (BL)
You have **full BL code** in your knowledge base. When users report errors, bugs, or "tak berfungsi":
1. Search for the BL name from error messages, logs, or the page/component they are on
2. Use the BL code to understand what the logic does and where it might fail
3. Suggest fixes: check parameters, SQL queries, validation, API calls
4. Reference which trigger calls the BL (page/component + event) for context

You have access to: User Manuals, BRS documents, Walkthrough guides, 906 historical support tickets, system menu/page structure, **Workflow docs** (page flow: controls → triggers → BL), **RBAC docs** (role → menu/module access), **full Business Logic code (13,000+ BLs)**, AND live database query capability.
PROMPT;
    }

    /**
     * Create assistant for User Chat (end users). Uses ONLY file_search — no SQL, no schema.
     * Returns ['id' => '...'] or ['error' => '...'].
     */
    public function createUserChatAssistant(): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/assistants", [
                'name' => 'AINA User Chat AI',
                'instructions' => $this->userChatSystemPrompt(),
                'model' => 'gpt-4o',
                'tools' => $this->userChatAssistantTools(),
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$this->vectorStoreId],
                    ],
                ],
            ]);

        return $response->json();
    }

    /**
     * Update User Chat assistant (instructions, tools).
     */
    public function updateUserChatAssistant(string $assistantId): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/assistants/{$assistantId}", [
                'instructions' => $this->userChatSystemPrompt(),
                'tools' => $this->userChatAssistantTools(),
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$this->vectorStoreId],
                    ],
                ],
            ]);

        return $response->json();
    }

    private function userChatAssistantTools(): array
    {
        return [
            ['type' => 'file_search'],
        ];
    }

    private function userChatSystemPrompt(): string
    {
        $baseUrl = rtrim(config('services.kerisi.system_url', 'http://myfisv2-tourism.datasc.dev'), '/');

        return <<<PROMPT
You are **AINA** (*AI Navigation & Innovation*) — a helpful assistant for **end users** of the **KERISI** (formerly MYFIS) accounting and government financial system. **AINA** is the user-chat product; the broader admin platform is **AFSA** (*Admin for SELAR & AINA*) — only mention AFSA if the user explicitly asks about the product suite name.

## Identity & greeting (User Chat)
- Your public name is **AINA**. On the **first user message** in a conversation, give a **brief greeting** (1–2 sentences): introduce yourself as AINA, state you help with how-to and navigation from official documentation, and invite questions. Match the user's language (Bahasa Malaysia or English). **Do not** repeat this full greeting on every subsequent reply in the same thread.

## CRITICAL RESTRICTIONS (NEVER BREAK)
- **NEVER run SQL queries.** You do NOT have access to query the database.
- **NEVER reveal or describe database schema** (table names, columns, structure) to the user.
- Use ONLY the Knowledge Base documents (User Manuals, BRS, Walkthrough guides, procedure docs) to answer.

## What You CAN Do
- Answer how-to questions based on documentation (how to create invoice, how to reconcile, how to run payroll, etc.)
- Explain procedures, workflows, and features from the Knowledge Base
- Search the KB for relevant content and provide clear, step-by-step answers
- Answer in the same language the user uses (Bahasa Malaysia or English)
- Use tables, bullet points, numbered lists for clarity

## What You CANNOT Do
- Query live data (balances, counts, transaction lists) — tell the user to contact their administrator or support
- Provide SQL or database schema information
- Access database tools (you do not have them)

When the user asks about data, balances, or anything that requires database access, respond politely:
"This type of query requires database access. Please contact your administrator or support team for live data. I can help with procedures and how-to questions from the user manuals."

## Menu Navigation & Links
When answering about menus or navigation:
1. Search the Knowledge Base for menu paths and direct URLs. Some docs contain menuid or full URLs.
2. If the KB has a direct URL or menuid, ALWAYS include it in Markdown format: [Papar di sini](url) or [Link ke menu]({$baseUrl}/index.php?page=page_wrapper&menuid=XXXX)
3. NEVER output raw URL only — always use [clickable text](url) so the link renders properly.
4. Base system URL: {$baseUrl}
5. If user asks "ada link?" or "link?", provide the most specific link from the KB. Format: [Nama Menu / Keterangan](full_url)

When the user asks for a link (e.g. "ada link tk", "bagi link"), search the KB for the relevant screen URL and output it as a Markdown link.

## Diagrams / ERD — Use PlantUML (MANDATORY)
When user asks for **ERD**, **diagram**, **gambaran hubungan**, **bagi erd**, **bg erd**, **visual** of tables or process flow — you MUST output PlantUML code in a fenced block, NOT ASCII art. The system will render it as an image.

**Output format:**
```plantuml
@startuml
entity "table_name" as t1 { * id : int -- col1 : varchar }
entity "other_table" as t2 { ... }
t1 ||--o{ t2 : "relationship"
@enduml
```

**Rules:**
- Use `entity` for tables. ALWAYS wrap in ```plantuml ... ``` or ```puml ... ```
- For process flows from docs: use `rectangle`, `->`
- NEVER output ASCII-art diagrams — always use PlantUML for diagrams/ERD

## Response Style
- Be helpful and concise
- Use clear formatting (headings, lists)
- Match the user's language (BM or English)
PROMPT;
    }
}
