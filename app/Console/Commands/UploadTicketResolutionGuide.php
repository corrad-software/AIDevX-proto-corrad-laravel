<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class UploadTicketResolutionGuide extends Command
{
    protected $signature = 'kerisi:upload-ticket-guide';

    protected $description = 'Upload kerisi-ticket-resolution-guide.md to OpenAI Vector Store (no DB required)';

    public function handle(): int
    {
        $this->info('📤 Uploading Ticket Resolution Guide to Vector Store...');

        $path = 'kerisi-knowledge/kerisi-ticket-resolution-guide.md';

        if (! Storage::disk('local')->exists($path)) {
            $this->error("File not found: {$path}");

            return 1;
        }

        $fullPath = Storage::disk('local')->path($path);
        $filename = basename($path);

        try {
            $openAI = app(OpenAIService::class);
            $result = $openAI->uploadFileToVectorStore($fullPath, $filename);

            if (isset($result['error'])) {
                $this->error('Upload failed: '.($result['error'] ?? json_encode($result)));

                return 1;
            }

            $this->info('✅ Ticket Resolution Guide uploaded successfully');
            $this->line('   File ID: '.($result['file_id'] ?? 'N/A'));

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return 1;
        }
    }
}
