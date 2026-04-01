<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use Illuminate\Console\Command;

class SetupUserChatAssistant extends Command
{
    protected $signature = 'kerisi:setup-user-chat';

    protected $description = 'Create or update KERISI User Chat assistant (KB only, no SQL/schema). Outputs OPENAI_USER_CHAT_ASSISTANT_ID for .env';

    public function handle(): int
    {
        $this->info('🔧 Setting up KERISI User Chat assistant...');

        $existingId = config('services.openai.user_chat_assistant_id');

        try {
            $openAI = app(OpenAIService::class);

            if ($existingId) {
                $result = $openAI->updateUserChatAssistant($existingId);
                $this->info('✅ User Chat assistant updated');
                $this->line('   ID: '.($result['id'] ?? $existingId));
            } else {
                $result = $openAI->createUserChatAssistant();
                if (isset($result['error'])) {
                    $this->error('OpenAI error: '.($result['error']['message'] ?? json_encode($result['error'])));

                    return 1;
                }
                $id = $result['id'] ?? null;
                if (! $id) {
                    $this->error('Failed to create assistant');

                    return 1;
                }
                $this->info('✅ User Chat assistant created');
                $this->newLine();
                $this->line('Add to your .env file:');
                $this->line('');
                $this->line('OPENAI_USER_CHAT_ASSISTANT_ID='.$id);
                $this->newLine();
            }

            $tools = array_column($result['tools'] ?? [], 'type');
            $this->line('   Tools: '.implode(', ', $tools));

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return 1;
        }
    }
}
