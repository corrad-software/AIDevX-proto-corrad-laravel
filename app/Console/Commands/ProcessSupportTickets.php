<?php

namespace App\Console\Commands;

use App\Models\Desk365SyncLog;
use App\Models\KnowledgeDocument;
use App\Services\Desk365Service;
use App\Services\OpenAIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessSupportTickets extends Command
{
    protected $signature = 'kerisi:process-tickets
                            {--file= : Path to CSV file (default: storage/app/private/kerisi-support-tickets-raw.csv)}
                            {--from-api : Fetch tickets from Desk365 API instead of CSV (requires DESK365_API_KEY)}
                            {--upload : Upload to OpenAI Vector Store after processing}';

    protected $description = 'Process Desk365 support ticket CSV and convert to AI knowledge documents';

    private OpenAIService $openAI;

    public function handle(): int
    {
        $this->info('🎫 KERISI Support Ticket Processor');
        $this->info('====================================');

        $this->openAI = app(OpenAIService::class);
        Storage::disk('local')->makeDirectory('kerisi-knowledge');

        $tickets = $this->option('from-api')
            ? $this->fetchFromDesk365Api()
            : $this->parseCsv(
                $this->option('file') ?? storage_path('app/private/kerisi-support-tickets-raw.csv')
            );

        if (empty($tickets)) {
            $this->error('No tickets to process.');

            return 1;
        }

        $this->info('📋 Loaded '.count($tickets).' tickets');

        // Group by module
        $grouped = $this->groupByModule($tickets);
        $this->info('📦 Grouped into '.count($grouped).' modules');
        $this->newLine();

        // Generate knowledge docs
        $files = [];
        $ticketCountByPath = [];
        $bar = $this->output->createProgressBar(count($grouped));
        $bar->start();

        foreach ($grouped as $module => $moduleTickets) {
            $content = $this->buildTicketDoc($module, $moduleTickets);
            $slug = 'kerisi-tickets-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $module));
            $path = 'kerisi-knowledge/'.$slug.'.md';

            Storage::disk('local')->put($path, $content);
            $this->line("  ✅ {$module} — ".count($moduleTickets).' tiket ('.number_format(strlen($content)).' chars)');

            $files[] = $path;
            $ticketCountByPath[$path] = count($moduleTickets);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('📄 Generated '.count($files).' knowledge documents from support tickets');

        if ($this->option('upload')) {
            [$uploaded, $failed] = $this->uploadToVectorStore($files, $ticketCountByPath);
            if ($this->option('from-api')) {
                Desk365SyncLog::create([
                    'user_id' => null,
                    'triggered_by' => 'scheduler',
                    'total_tickets' => count($tickets),
                    'modules_synced' => count($files),
                    'uploaded' => $uploaded,
                    'failed' => $failed,
                    'status' => $failed === 0 ? 'success' : 'failed',
                    'message' => "{$uploaded} modul disegerakkan. {$failed} gagal.",
                ]);
            }
        } else {
            $this->info('💡 Run with --upload to push to OpenAI Vector Store');
        }

        return 0;
    }

    private function fetchFromDesk365Api(): array
    {
        $desk365 = app(Desk365Service::class);
        if (! $desk365->isConfigured()) {
            $this->error('DESK365_API_KEY not set in .env. Add DESK365_BASE_URL and DESK365_API_KEY.');

            return [];
        }
        $this->info('📡 Fetching tickets from Desk365 API...');
        $tickets = $desk365->fetchAllTicketsForKnowledge();
        if (isset($tickets['error'])) {
            $this->error('Desk365 API error: '.($tickets['error'] ?? 'Unknown'));

            return [];
        }

        return $tickets;
    }

    private function parseCsv(string $path): array
    {
        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return [];
        }
        $tickets = [];
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }
        $headers = fgetcsv($handle, 0, ',');
        if (! $headers) {
            fclose($handle);

            return [];
        }
        $headerCount = count($headers);

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            // Pad or trim row to match header count (CSV may have inconsistent columns)
            if (count($row) < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif (count($row) > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }
            $ticket = array_combine($headers, $row);
            // Skip only truly empty tickets
            $subject = trim($ticket['Subject'] ?? '');
            $desc = trim($ticket['Description'] ?? '');
            if ($subject === '' && $desc === '') {
                continue;
            }
            if (stripos($subject, 'contoh aduan') !== false) {
                continue;
            }

            $tickets[] = $ticket;
        }

        fclose($handle);

        return $tickets;
    }

    private function groupByModule(array $tickets): array
    {
        $grouped = [];
        foreach ($tickets as $ticket) {
            $module = trim($ticket['SubCategory'] ?? '');
            if (empty($module)) {
                $module = $this->inferModule($ticket['Subject'] ?? '', $ticket['Description'] ?? '');
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
            'PAYROLL' => 'Payroll',
            'GAJI' => 'Payroll',
            'PINJAMAN' => 'Loan',
            'LOAN' => 'Loan',
            'BAJET' => 'Budget',
            'BUDGET' => 'Budget',
            'VIREMENT' => 'Budget',
            'VOUCHER' => 'Account Payable',
            'BAUCER' => 'Account Payable',
            'INVOICE' => 'Account Payable',
            'JURNAL' => 'General Ledger',
            'CASHBOOK' => 'Cashbook',
            'ASET' => 'Asset',
            'ASSET' => 'Asset',
            'USERNAME' => 'System Administrator',
            'PASSWORD' => 'System Administrator',
            'STAFF BARU' => 'System Administrator',
            'VENDOR' => 'Portal Vendor',
            'RECEIPT' => 'Cashbook',
        ];

        foreach ($map as $keyword => $module) {
            if (str_contains($text, $keyword)) {
                return $module;
            }
        }

        return 'General';
    }

    /** Classify severity: big (critical), medium, small */
    private function inferSeverity(array $ticket): string
    {
        $priority = strtoupper(trim($ticket['Priority'] ?? ''));
        $type = strtoupper(trim($ticket['Type'] ?? ''));

        if ($type === 'BUG' || in_array($priority, ['HIGH', 'CRITICAL', 'URGENT'])) {
            return 'big';
        }
        if ($type === 'ISSUE' || $priority === 'MEDIUM') {
            return 'medium';
        }
        if ($type === 'QUESTION' || $priority === 'LOW') {
            return 'small';
        }

        return 'medium'; // default
    }

    private function buildTicketDoc(string $module, array $tickets): string
    {
        $total = count($tickets);
        $bugs = count(array_filter($tickets, fn ($t) => strtolower($t['Type'] ?? '') === 'bug'));
        $issues = count(array_filter($tickets, fn ($t) => strtolower($t['Type'] ?? '') === 'issue'));
        $queries = count(array_filter($tickets, fn ($t) => strtolower($t['Type'] ?? '') === 'question'));

        $small = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'small'));
        $medium = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'medium'));
        $big = count(array_filter($tickets, fn ($t) => $this->inferSeverity($t) === 'big'));

        $content = "# KERISI Support Tickets: {$module}\n\n";
        $content .= "This document contains real support tickets submitted by users for the **{$module}** module of the KERISI system.\n";
        $content .= "Use this to understand common user problems, error patterns, and how issues were resolved.\n\n";
        $content .= "**Statistics:** {$total} tickets total | {$bugs} bugs | {$issues} issues | {$queries} questions\n";
        $content .= "**Severity:** 🔴 Big: {$big} | 🟡 Medium: {$medium} | 🟢 Small: {$small}\n\n";
        $content .= "---\n\n";

        // Group by severity for clearer structure
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
                $num = $ticket['Ticket Number'] ?? '';
                $subject = trim($ticket['Subject'] ?? 'No Subject');
                $type = $ticket['Type'] ?? '';
                $priority = $ticket['Priority'] ?? '';
                $status = $ticket['Status'] ?? '';
                $contact = $ticket['Contact Name'] ?? '';
                $company = $ticket['Company Name'] ?? '';
                $createdAt = $ticket['Created Time'] ?? '';

                $desc = $this->cleanDescription($ticket['Description'] ?? '');
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
        // Remove common email boilerplate patterns
        $boilerplate = [
            '/GO GREEN-PAPERLESS.*?printing\s*/si',
            '/This message contains confidential.*?system\.\s*/si',
            '/MALAYSIA MADANI.*?BERKHIDMAT UNTUK NEGARA/si',
            '/Saya yang menjalankan amanah.*?\n/si',
            '/Tel\s*:.*?\n/i',
            '/Faks?\s*:.*?\n/i',
            '/D\/L\s*:.*?\n/i',
            '/URL\s*:.*?\n/i',
            '/www\.[a-z.]+/i',
            '/\+?60[0-9\-\s]{8,}/i',  // Phone numbers
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/i',  // Emails
        ];

        $text = $raw;
        foreach ($boilerplate as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        // Remove excess whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Truncate very long descriptions (keep first 800 chars)
        if (strlen($text) > 800) {
            $text = substr($text, 0, 800).'...';
        }

        return $text;
    }

    /**
     * @return array{0: int, 1: int} [uploaded, failed]
     */
    private function uploadToVectorStore(array $filePaths, array $ticketCountByPath = []): array
    {
        $this->newLine();
        $this->info('⬆️  Uploading to OpenAI Vector Store...');
        $bar = $this->output->createProgressBar(count($filePaths));
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($filePaths as $path) {
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
                    $success++;
                } else {
                    $failed++;
                    $this->newLine();
                    $this->warn("  ⚠️  Failed: {$filename}");
                }
            } catch (\Exception $e) {
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ Uploaded: {$success} | ❌ Failed: {$failed}");

        return [$success, $failed];
    }
}
