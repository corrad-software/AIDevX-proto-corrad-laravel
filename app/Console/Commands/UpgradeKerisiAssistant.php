<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use Illuminate\Console\Command;

class UpgradeKerisiAssistant extends Command
{
    protected $signature = 'kerisi:upgrade-ai';

    protected $description = 'Upgrade KERISI Support AI assistant with latest instructions and tools';

    public function handle(): int
    {
        $this->info('⬆️  Upgrading KERISI Support AI...');

        $assistantId = config('services.openai.assistant_id');

        if (! $assistantId) {
            $this->error('OPENAI_ASSISTANT_ID not configured in .env');

            return 1;
        }

        try {
            $openAI = app(OpenAIService::class);
            $result = $openAI->updateAssistant($assistantId);

            $this->info('✅ Assistant updated successfully');
            $this->line('   ID: '.($result['id'] ?? $assistantId));
            $this->line('   Tools: '.implode(', ', array_column($result['tools'] ?? [], 'type')));

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return 1;
        }
    }
}
