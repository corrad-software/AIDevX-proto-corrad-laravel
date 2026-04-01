<?php

namespace App\Services;

use App\Models\InternalTicketSyncLog;
use App\Models\KnowledgeDocument;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Sync internal (Kerisi / AFSA) support tickets to OpenAI Vector Store for RAG — same idea as Desk365 ticket sync.
 */
class InternalTicketSyncService
{
    public function __construct(
        protected OpenAIService $openAI,
    ) {}

    /**
     * @return array{success: bool, total_tickets: int, modules_synced: int, uploaded: int, failed: int, message: string}
     */
    public function syncAll(string $triggeredBy = 'api'): array
    {
        if (empty(config('services.openai.key')) || empty(config('services.openai.vector_store_id'))) {
            $this->logSync(0, 0, 0, 0, 'failed', 'OPENAI_API_KEY atau OPENAI_VECTOR_STORE_ID tidak ditetapkan dalam .env', $triggeredBy);

            return [
                'success' => false,
                'total_tickets' => 0,
                'modules_synced' => 0,
                'uploaded' => 0,
                'failed' => 0,
                'message' => 'OpenAI / Vector Store tidak dikonfigurasi',
            ];
        }

        $tickets = SupportTicket::query()
            ->with(['messages.user:id,name', 'requestor:id,name,email', 'assignee:id,name,email'])
            ->orderBy('id')
            ->get();

        if ($tickets->isEmpty()) {
            $this->logSync(0, 0, 0, 0, 'success', 'Tiada tiket dalaman untuk disegerakkan', $triggeredBy);

            return [
                'success' => true,
                'total_tickets' => 0,
                'modules_synced' => 0,
                'uploaded' => 0,
                'failed' => 0,
                'message' => 'Tiada tiket dalaman untuk disegerakkan',
            ];
        }

        $rows = [];
        foreach ($tickets as $t) {
            $rows[] = $this->ticketToKnowledgeRow($t);
        }

        $grouped = $this->groupByModule($rows);
        Storage::disk('local')->makeDirectory('kerisi-knowledge');

        $files = [];
        $ticketCountByPath = [];
        foreach ($grouped as $module => $moduleTickets) {
            $content = $this->buildModuleMarkdown($module, $moduleTickets);
            $slug = 'kerisi-afsa-tickets-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $module));
            $path = 'kerisi-knowledge/'.$slug.'.md';
            Storage::disk('local')->put($path, $content);
            $files[] = $path;
            $ticketCountByPath[$path] = count($moduleTickets);
        }

        $uploaded = 0;
        $failed = 0;
        foreach ($files as $path) {
            try {
                $fullPath = Storage::disk('local')->path($path);
                $filename = basename($path);
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $result = $this->openAI->uploadFileToVectorStore($fullPath, $filename);

                if (isset($result['file_id'])) {
                    $module = ucwords(str_replace('-', ' ', preg_replace('/^kerisi-afsa-tickets-/', '', $name)));
                    $ticketCount = $ticketCountByPath[$path] ?? 0;
                    KnowledgeDocument::updateOrCreate(
                        ['original_filename' => $filename],
                        [
                            'name' => "AFSA Internal Tickets: {$module}",
                            'file_path' => $path,
                            'file_type' => 'md',
                            'file_size' => filesize($fullPath),
                            'module' => $module,
                            'openai_file_id' => $result['file_id'],
                            'status' => 'uploaded',
                            'notes' => json_encode(['source' => 'internal_tickets', 'ticket_count' => $ticketCount]),
                        ]
                    );
                    $uploaded++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $message = "{$uploaded} modul tiket dalaman disegerakkan ke AI. ".count($rows).' tiket termasuk.';
        $uploadedModules = array_keys($grouped);
        $ticketNumbers = array_values(array_filter(array_map(fn ($t) => $t['Ticket Number'] ?? '', $rows)));
        $moduleCounts = [];
        foreach ($grouped as $module => $moduleTickets) {
            $moduleCounts[] = ['module' => $module, 'count' => count($moduleTickets)];
        }
        $ticketDetails = [];
        foreach ($grouped as $module => $moduleTickets) {
            foreach ($moduleTickets as $t) {
                $ticketDetails[] = [
                    'ticket_number' => $t['Ticket Number'] ?? '',
                    'subject' => $t['Subject'] ?? '',
                    'description' => mb_substr($t['Description'] ?? '', 0, 500),
                    'module' => $module,
                    'status' => $t['Status'] ?? '',
                    'type' => $t['Type'] ?? '',
                    'priority' => $t['Priority'] ?? '',
                    'contact_name' => $t['Contact Name'] ?? '',
                    'company_name' => $t['Company Name'] ?? '',
                    'created_time' => $t['Created Time'] ?? '',
                    'assigned_agent' => $t['Assigned Agent'] ?? '',
                ];
            }
        }

        $this->logSync(
            count($rows),
            count($files),
            $uploaded,
            $failed,
            $failed === 0 ? 'success' : 'failed',
            $message,
            $triggeredBy,
            $uploadedModules,
            $ticketNumbers,
            $moduleCounts,
            $ticketDetails
        );

        return [
            'success' => $failed === 0,
            'total_tickets' => count($rows),
            'modules_synced' => count($files),
            'uploaded' => $uploaded,
            'failed' => $failed,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketToKnowledgeRow(SupportTicket $t): array
    {
        $publicLines = [];
        $internalLines = [];
        foreach ($t->messages as $m) {
            $who = $m->user?->name ?? 'Pengguna';
            $line = "{$who}: {$m->message}";
            if ($m->is_internal) {
                $internalLines[] = $line;
            } else {
                $publicLines[] = $line;
            }
        }

        $desc = (string) $t->description;
        if ($publicLines !== []) {
            $desc .= "\n\n---\n**Perbualan (pengguna / ejen):**\n".implode("\n\n", $publicLines);
        }
        if ($internalLines !== []) {
            $desc .= "\n\n---\n**[NOTA DALAMAN — rujukan teknikal staf]**\n".implode("\n\n", $internalLines);
        }

        $requestor = $t->requestor?->name ?? '';
        $assignee = $t->assignee?->name ?? '';

        return [
            'Ticket Number' => $t->ticket_number,
            'Subject' => $t->subject,
            'Description' => $desc,
            'SubCategory' => $t->module ?? '',
            'Type' => $t->type ?? '',
            'Priority' => $t->priority ?? '',
            'Status' => $t->status ?? '',
            'Contact Name' => $requestor,
            'Company Name' => $t->customer_name ?? '',
            'Created Time' => $t->created_at?->toIso8601String() ?? '',
            'Assigned Agent' => $assignee,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tickets
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByModule(array $tickets): array
    {
        $grouped = [];
        foreach ($tickets as $ticket) {
            $module = trim((string) ($ticket['SubCategory'] ?? ''));
            if ($module === '') {
                $module = $this->inferModule(
                    (string) ($ticket['Subject'] ?? ''),
                    (string) ($ticket['Description'] ?? '')
                );
            }
            $grouped[$module][] = $ticket;
        }
        ksort($grouped);

        return $grouped;
    }

    private function inferModule(string $subject, string $desc): string
    {
        $text = strtoupper($subject.' '.substr($desc, 0, 300));
        $map = [
            'PAYROLL' => 'Payroll', 'GAJI' => 'Payroll', 'PINJAMAN' => 'Loan', 'LOAN' => 'Loan',
            'BAJET' => 'Budget', 'BUDGET' => 'Budget', 'VIREMENT' => 'Budget',
            'VOUCHER' => 'Account Payable', 'BAUCER' => 'Account Payable', 'INVOICE' => 'Account Payable',
            'JURNAL' => 'General Ledger', 'CASHBOOK' => 'Cashbook', 'ASET' => 'Asset', 'ASSET' => 'Asset',
            'USERNAME' => 'System Administrator', 'PASSWORD' => 'System Administrator', 'STAFF BARU' => 'System Administrator',
            'VENDOR' => 'Portal Vendor', 'RECEIPT' => 'Cashbook',
        ];
        foreach ($map as $keyword => $module) {
            if (str_contains($text, $keyword)) {
                return $module;
            }
        }

        return 'General';
    }

    private function inferSeverity(array $ticket): string
    {
        $priority = strtoupper(trim((string) ($ticket['Priority'] ?? '')));
        $type = strtoupper(trim((string) ($ticket['Type'] ?? '')));
        if ($type === 'BUG' || in_array($priority, ['HIGH', 'CRITICAL', 'URGENT'], true)) {
            return 'big';
        }
        if ($type === 'ISSUE' || $priority === 'MEDIUM') {
            return 'medium';
        }
        if ($type === 'QUESTION' || $priority === 'LOW') {
            return 'small';
        }

        return 'medium';
    }

    /**
     * @param  array<int, array<string, mixed>>  $tickets
     */
    private function buildModuleMarkdown(string $module, array $tickets): string
    {
        $total = count($tickets);
        $bugs = count(array_filter($tickets, fn ($t) => strtolower((string) ($t['Type'] ?? '')) === 'bug'));
        $issues = count(array_filter($tickets, fn ($t) => strtolower((string) ($t['Type'] ?? '')) === 'issue'));
        $queries = count(array_filter($tickets, fn ($t) => strtolower((string) ($t['Type'] ?? '')) === 'question'));
        $small = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'small'));
        $medium = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'medium'));
        $big = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'big'));

        $content = "# AFSA / KERISI — Tiket sokongan dalaman: {$module}\n\n";
        $content .= "Dokumen ini mengandungi tiket **dalam aplikasi** (bukan Desk365) untuk modul **{$module}**.\n";
        $content .= "Gunakan untuk sokongan pengguna akhir dan rujukan teknikal (termasuk bahagian nota dalaman jika ada).\n\n";
        $content .= "**Statistik:** {$total} tiket | {$bugs} bug | {$issues} isu | {$queries} soalan\n";
        $content .= "**Keterukan:** 🔴 Besar: {$big} | 🟡 Sederhana: {$medium} | 🟢 Kecil: {$small}\n\n---\n\n";

        $bySeverity = ['big' => [], 'medium' => [], 'small' => []];
        foreach ($tickets as $ticket) {
            $bySeverity[$this->inferSeverity($ticket)][] = $ticket;
        }
        $severityLabels = ['big' => '🔴 Besar (kritikal)', 'medium' => '🟡 Sederhana', 'small' => '🟢 Kecil'];
        foreach (['big', 'medium', 'small'] as $sev) {
            if (empty($bySeverity[$sev])) {
                continue;
            }
            $content .= "## {$severityLabels[$sev]} — ".count($bySeverity[$sev])." tiket\n\n";
            foreach ($bySeverity[$sev] as $ticket) {
                $num = $ticket['Ticket Number'] ?? '';
                $subject = trim((string) ($ticket['Subject'] ?? 'Tiada subjek'));
                $type = $ticket['Type'] ?? '';
                $priority = $ticket['Priority'] ?? '';
                $status = $ticket['Status'] ?? '';
                $contact = $ticket['Contact Name'] ?? '';
                $company = $ticket['Company Name'] ?? '';
                $createdAt = $ticket['Created Time'] ?? '';
                $desc = $this->cleanDescription((string) ($ticket['Description'] ?? ''));
                if ($subject === '' && $desc === '') {
                    continue;
                }
                $content .= "### Tiket #{$num}: {$subject}\n\n";
                $meta = array_filter([
                    $type ? "**Jenis:** {$type}" : null,
                    $priority ? "**Keutamaan:** {$priority}" : null,
                    $status ? "**Status:** {$status}" : null,
                    $contact ? "**Pemohon:** {$contact}" : null,
                    $company ? "**Organisasi / sistem:** {$company}" : null,
                    $createdAt ? "**Tarikh:** {$createdAt}" : null,
                ]);
                $content .= implode(' | ', $meta)."\n\n";
                if ($desc !== '') {
                    $content .= "**Kandungan / perbualan:**\n{$desc}\n\n";
                }
                $content .= "---\n\n";
            }
        }

        return $content;
    }

    private function cleanDescription(string $raw): string
    {
        $boilerplate = [
            '/GO GREEN-PAPERLESS.*?printing\s*/si', '/This message contains confidential.*?system\.\s*/si',
            '/MALAYSIA MADANI.*?BERKHIDMAT UNTUK NEGARA/si', '/Saya yang menjalankan amanah.*?\n/si',
            '/Tel\s*:.*?\n/i', '/Faks?\s*:.*?\n/i', '/D\/L\s*:.*?\n/i', '/URL\s*:.*?\n/i',
        ];
        $text = $raw;
        foreach ($boilerplate as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text)) ?? $text;

        return strlen($text) > 12000 ? substr($text, 0, 12000).'...' : $text;
    }

    private function logSync(
        int $totalTickets,
        int $modulesSynced,
        int $uploaded,
        int $failed,
        string $status,
        string $message,
        string $triggeredBy,
        array $uploadedModules = [],
        array $ticketNumbers = [],
        array $moduleCounts = [],
        array $ticketDetails = [],
    ): ?InternalTicketSyncLog {
        return InternalTicketSyncLog::create([
            'user_id' => Auth::id(),
            'triggered_by' => $triggeredBy,
            'total_tickets' => $totalTickets,
            'modules_synced' => $modulesSynced,
            'uploaded' => $uploaded,
            'failed' => $failed,
            'status' => $status,
            'message' => $message,
            'uploaded_modules' => $uploadedModules,
            'uploaded_ticket_numbers' => $ticketNumbers,
            'uploaded_module_counts' => $moduleCounts,
            'uploaded_ticket_details' => $ticketDetails,
        ]);
    }
}
