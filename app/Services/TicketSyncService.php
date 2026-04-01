<?php

namespace App\Services;

use App\Models\Desk365SyncLog;
use App\Models\KnowledgeDocument;
use App\Models\SyncedTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TicketSyncService
{
    public function __construct(
        protected Desk365Service $desk365,
        protected OpenAIService $openAI,
        protected AppNotificationService $appNotifications,
    ) {}

    /**
     * Sync tickets from Desk365 API to OpenAI Vector Store.
     * Agent AI akan dapat akses ticket selepas sync.
     *
     * @param  string  $triggeredBy  manual, scheduler, api
     * @return array{success: bool, total_tickets: int, modules_synced: int, uploaded: int, failed: int, message: string}
     */
    public function syncFromDesk365(string $triggeredBy = 'manual'): array
    {
        if (! $this->desk365->isConfigured()) {
            $this->logSync(0, 0, 0, 0, 'failed', 'DESK365_API_KEY not set in .env', $triggeredBy);

            return [
                'success' => false,
                'total_tickets' => 0,
                'modules_synced' => 0,
                'uploaded' => 0,
                'failed' => 0,
                'message' => 'DESK365_API_KEY not set in .env',
            ];
        }

        $tickets = $this->desk365->fetchAllTicketsForKnowledge();
        if (isset($tickets['error'])) {
            $this->logSync(0, 0, 0, 0, 'failed', 'Desk365 API error: '.($tickets['error'] ?? 'Unknown'), $triggeredBy);

            return [
                'success' => false,
                'total_tickets' => 0,
                'modules_synced' => 0,
                'uploaded' => 0,
                'failed' => 0,
                'message' => 'Desk365 API error: '.($tickets['error'] ?? 'Unknown'),
            ];
        }

        if (empty($tickets)) {
            $this->logSync(0, 0, 0, 0, 'success', 'Tiada ticket dari Desk365', $triggeredBy);

            return [
                'success' => true,
                'total_tickets' => 0,
                'modules_synced' => 0,
                'uploaded' => 0,
                'failed' => 0,
                'message' => 'Tiada ticket dari Desk365',
            ];
        }

        $grouped = $this->groupByModule($tickets);
        Storage::disk('local')->makeDirectory('kerisi-knowledge');

        $files = [];
        $ticketCountByPath = [];
        foreach ($grouped as $module => $moduleTickets) {
            $content = $this->buildTicketDoc($module, $moduleTickets);
            $slug = 'kerisi-tickets-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $module));
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
                    $module = ucwords(str_replace('-', ' ', preg_replace('/^kerisi-tickets-/', '', $name)));
                    $ticketCount = $ticketCountByPath[$path] ?? 0;
                    KnowledgeDocument::updateOrCreate(
                        ['original_filename' => $filename],
                        [
                            'name' => "Support Tickets: {$module}",
                            'file_path' => $path,
                            'file_type' => 'md',
                            'file_size' => filesize($fullPath),
                            'module' => $module,
                            'openai_file_id' => $result['file_id'],
                            'status' => 'uploaded',
                            'notes' => json_encode(['ticket_count' => $ticketCount]),
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

        $message = "{$uploaded} modul ticket disegerakkan ke AI. Agent kini boleh akses ".count($tickets).' ticket.';
        $uploadedModules = array_keys($grouped);
        $ticketNumbers = array_values(array_filter(array_map(fn ($t) => $t['Ticket Number'] ?? $t['ticket_number'] ?? '', $tickets)));
        $moduleCounts = [];
        foreach ($grouped as $module => $moduleTickets) {
            $moduleCounts[] = ['module' => $module, 'count' => count($moduleTickets)];
        }
        $ticketDetails = [];
        foreach ($grouped as $module => $moduleTickets) {
            foreach ($moduleTickets as $t) {
                $ticketDetails[] = [
                    'ticket_number' => $t['Ticket Number'] ?? $t['ticket_number'] ?? '',
                    'subject' => $t['Subject'] ?? $t['subject'] ?? '',
                    'description' => mb_substr($t['Description'] ?? $t['description'] ?? '', 0, 500),
                    'module' => $module,
                    'status' => $t['Status'] ?? $t['status'] ?? '',
                    'type' => $t['Type'] ?? $t['type'] ?? '',
                    'priority' => $t['Priority'] ?? $t['priority'] ?? '',
                    'contact_name' => $t['Contact Name'] ?? $t['contact_name'] ?? '',
                    'company_name' => $t['Company Name'] ?? $t['company_name'] ?? '',
                    'created_time' => $t['Created Time'] ?? $t['created_time'] ?? '',
                    'assigned_agent' => $t['Assigned Agent'] ?? $t['assigned_agent'] ?? '',
                ];
            }
        }
        $syncLog = $this->logSync(count($tickets), count($files), $uploaded, $failed, $failed === 0 ? 'success' : 'failed', $message, $triggeredBy, $uploadedModules, $ticketNumbers, $moduleCounts, $ticketDetails);
        $this->saveTicketsToDb($ticketDetails, $syncLog?->id);

        if ($syncLog && $failed === 0) {
            $this->appNotifications->notifyDesk365NewTickets($syncLog, $ticketDetails, $ticketNumbers);
        }

        return [
            'success' => $failed === 0,
            'total_tickets' => count($tickets),
            'modules_synced' => count($files),
            'uploaded' => $uploaded,
            'failed' => $failed,
            'message' => $message,
        ];
    }

    private function logSync(int $totalTickets, int $modulesSynced, int $uploaded, int $failed, string $status, string $message, string $triggeredBy, array $uploadedModules = [], array $ticketNumbers = [], array $moduleCounts = [], array $ticketDetails = []): ?Desk365SyncLog
    {
        return Desk365SyncLog::create([
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

    private function saveTicketsToDb(array $ticketDetails, ?int $syncLogId): void
    {
        if (empty($ticketDetails)) {
            return;
        }
        DB::transaction(function () use ($ticketDetails, $syncLogId) {
            SyncedTicket::query()->delete();
            $chunks = array_chunk($ticketDetails, 100);
            foreach ($chunks as $chunk) {
                $rows = array_map(fn ($t) => [
                    'ticket_number' => $t['ticket_number'] ?? '',
                    'subject' => $t['subject'] ?? '',
                    'description' => $t['description'] ?? '',
                    'module' => $t['module'] ?? '',
                    'status' => $t['status'] ?? '',
                    'type' => $t['type'] ?? '',
                    'priority' => $t['priority'] ?? '',
                    'contact_name' => $t['contact_name'] ?? '',
                    'company_name' => $t['company_name'] ?? '',
                    'created_time' => $t['created_time'] ?? '',
                    'assigned_agent' => $t['assigned_agent'] ?? '',
                    'desk365_sync_log_id' => $syncLogId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk);
                SyncedTicket::insert($rows);
            }
        });
    }

    private function groupByModule(array $tickets): array
    {
        $grouped = [];
        foreach ($tickets as $ticket) {
            $module = trim($ticket['SubCategory'] ?? $ticket['sub_category'] ?? '');
            if (empty($module)) {
                $module = $this->inferModule($ticket['Subject'] ?? $ticket['subject'] ?? '', $ticket['Description'] ?? $ticket['description'] ?? '');
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
        $priority = strtoupper(trim($ticket['Priority'] ?? $ticket['priority'] ?? ''));
        $type = strtoupper(trim($ticket['Type'] ?? $ticket['type'] ?? ''));
        if ($type === 'BUG' || in_array($priority, ['HIGH', 'CRITICAL', 'URGENT'])) {
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

    private function buildTicketDoc(string $module, array $tickets): string
    {
        $total = count($tickets);
        $bugs = count(array_filter($tickets, fn ($t) => strtolower($t['Type'] ?? $t['type'] ?? '') === 'bug'));
        $issues = count(array_filter($tickets, fn ($t) => strtolower($t['Type'] ?? $t['type'] ?? '') === 'issue'));
        $queries = count(array_filter($tickets, fn ($t) => strtolower($t['Type'] ?? $t['type'] ?? '') === 'question'));
        $small = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'small'));
        $medium = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'medium'));
        $big = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'big'));

        $content = "# KERISI Support Tickets: {$module}\n\n";
        $content .= "This document contains real support tickets submitted by users for the **{$module}** module of the KERISI system.\n";
        $content .= "Use this to understand common user problems, error patterns, and how issues were resolved.\n\n";
        $content .= "**Statistics:** {$total} tickets total | {$bugs} bugs | {$issues} issues | {$queries} questions\n";
        $content .= "**Severity:** 🔴 Big: {$big} | 🟡 Medium: {$medium} | 🟢 Small: {$small}\n\n---\n\n";

        $bySeverity = ['big' => [], 'medium' => [], 'small' => []];
        foreach ($tickets as $ticket) {
            $bySeverity[$this->inferSeverity($ticket)][] = $ticket;
        }
        $severityLabels = ['big' => '🔴 Big (Critical)', 'medium' => '🟡 Medium', 'small' => '🟢 Small'];
        foreach (['big', 'medium', 'small'] as $sev) {
            if (empty($bySeverity[$sev])) {
                continue;
            }
            $content .= "## {$severityLabels[$sev]} — ".count($bySeverity[$sev])." tickets\n\n";
            foreach ($bySeverity[$sev] as $ticket) {
                $num = $ticket['Ticket Number'] ?? $ticket['ticket_number'] ?? '';
                $subject = trim($ticket['Subject'] ?? $ticket['subject'] ?? 'No Subject');
                $type = $ticket['Type'] ?? $ticket['type'] ?? '';
                $priority = $ticket['Priority'] ?? $ticket['priority'] ?? '';
                $status = $ticket['Status'] ?? $ticket['status'] ?? '';
                $contact = $ticket['Contact Name'] ?? $ticket['contact_name'] ?? '';
                $company = $ticket['Company Name'] ?? $ticket['company_name'] ?? '';
                $createdAt = $ticket['Created Time'] ?? $ticket['created_time'] ?? '';
                $desc = $this->cleanDescription($ticket['Description'] ?? $ticket['description'] ?? '');
                if (empty($subject) && empty($desc)) {
                    continue;
                }
                $content .= "### Ticket #{$num}: {$subject}\n\n";
                $meta = array_filter([
                    $type ? "**Type:** {$type}" : null,
                    $priority ? "**Priority:** {$priority}" : null,
                    $status ? "**Status:** {$status}" : null,
                    $contact ? "**Pengguna:** {$contact}" : null,
                    $company ? "**Organisasi:** {$company}" : null,
                    $createdAt ? "**Tarikh:** {$createdAt}" : null,
                ]);
                $content .= implode(' | ', $meta)."\n\n";
                if ($desc) {
                    $content .= "**Masalah / Permintaan:**\n{$desc}\n\n";
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
            '/www\.[a-z.]+/i', '/\+?60[0-9\-\s]{8,}/i', '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/i',
        ];
        $text = $raw;
        foreach ($boilerplate as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text));

        return strlen($text) > 800 ? substr($text, 0, 800).'...' : $text;
    }
}
