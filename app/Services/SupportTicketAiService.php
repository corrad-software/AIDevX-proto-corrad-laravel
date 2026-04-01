<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Support\Facades\Log;

class SupportTicketAiService
{
    public function __construct(
        protected OpenAIService $openai,
    ) {}

    public function afterTicketCreated(SupportTicket $ticket): void
    {
        if (! $ticket->ai_assistance_enabled) {
            return;
        }

        $ticket->loadMissing('messages');
        $first = $ticket->messages->sortBy('id')->first();
        if (! $first || $first->is_ai_message) {
            return;
        }

        $this->appendAINAResponse($ticket, (string) $first->message);
    }

    public function afterRequestorReply(SupportTicket $ticket, string $latestUserPlainBody, int $requestorUserId): void
    {
        if (! $ticket->ai_assistance_enabled || $ticket->status === 'closed') {
            return;
        }

        if ($ticket->ai_awaiting_satisfaction) {
            $this->handleSatisfactionReply($ticket, $latestUserPlainBody, $requestorUserId);

            return;
        }

        $this->appendAINAResponse($ticket, $latestUserPlainBody);
    }

    private function appendAINAResponse(SupportTicket $ticket, string $latestUserText): void
    {
        $transcript = $this->buildTranscript($ticket);
        $result = $this->openai->generateTicketAINAAssistantReply(
            $transcript,
            (string) $ticket->subject,
            $ticket->module,
            $ticket->system_name,
        );

        $uid = (int) $ticket->created_by_user_id;

        if (isset($result['error'])) {
            Log::warning('ticket_aina_failed', [
                'ticket_id' => $ticket->id,
                'error' => $result['error'],
            ]);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $uid,
                'message' => "**AINA** *(penolong AI — jawapan automatik mungkin tidak tepat, terutamanya untuk logik perniagaan)*\n\n"
                    .'Maaf, penolong AI tidak dapat menjana jawapan buat masa ini. Sila tunggu sokongan daripada pasukan kami.',
                'is_internal' => false,
                'is_ai_message' => true,
            ]);
            $ticket->update(['ai_awaiting_satisfaction' => false]);

            return;
        }

        $body = $result['content'];
        $closing = "\n\n---\n**Adakah anda berpuas hati dengan jawapan ini?** Balas **ya** jika isu anda selesai dan kami boleh menutup tiket, atau **tidak** jika anda masih memerlukan sokongan manusia (ejen).";

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $uid,
            'message' => "**AINA** *(analisis AI — kemungkinan salah untuk kes BI yang kompleks; bukan ganti nasihat rasmi)*\n\n".$body.$closing,
            'is_internal' => false,
            'is_ai_message' => true,
        ]);

        $ticket->update(['ai_awaiting_satisfaction' => true]);
    }

    private function handleSatisfactionReply(SupportTicket $ticket, string $messageBody, int $requestorUserId): void
    {
        $polarity = $this->satisfactionPolarity($messageBody);
        $uid = (int) $ticket->created_by_user_id;

        if ($polarity === true) {
            $ticket->update([
                'status' => 'closed',
                'closed_by_user_id' => $requestorUserId,
                'closed_at' => now(),
                'ai_awaiting_satisfaction' => false,
            ]);
            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $uid,
                'message' => '**AINA**: Terima kasih atas pengesahan anda. Tiket ini **ditutup** kerana anda berpuas hati.',
                'is_internal' => false,
                'is_ai_message' => true,
            ]);

            return;
        }

        if ($polarity === false) {
            $nextStatus = $ticket->status;
            if (in_array((string) $ticket->status, ['new', 'assigned', 'pending_requestor'], true)) {
                $nextStatus = 'in_progress';
            }
            $ticket->update([
                'ai_awaiting_satisfaction' => false,
                'status' => $nextStatus,
            ]);
            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $uid,
                'message' => '**AINA**: Dimaklumkan. Pasukan sokongan manusia akan membantu anda seterusnya. Jika perlu, berikan butiran tambahan di bawah — ejen akan menyemak tiket ini.',
                'is_internal' => false,
                'is_ai_message' => true,
            ]);

            return;
        }

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $uid,
            'message' => '**AINA**: Sila jawab dengan jelas **ya** (puas hati, tutup tiket) atau **tidak** (perlukan sokongan manusia).',
            'is_internal' => false,
            'is_ai_message' => true,
        ]);
    }

    /**
     * @return ?bool true = satisfied / close, false = need human, null = unclear
     */
    private function satisfactionPolarity(string $text): ?bool
    {
        $t = mb_strtolower(trim(strip_tags($text)));
        if ($t === '') {
            return null;
        }

        $negative = (bool) preg_match(
            '/\b(tidak|tak)\b.*\bpuas\b|\b(tidak|tak)\s+puas\b|\bnot\s+satisfied\b|\bperlukan\s+ejen\b|\bsokongan\s+manusia\b|\bhuman\s+support\b|\bneed\s+(an?\s+)?agent\b|\btalk\s+to\s+support\b/u',
            $t,
        );

        $positive = (bool) preg_match(
            '/\b(ya|yes)\b|\bok\b|puas\s*hati|puashati|terima\s+kasih|thanks|thank\s+you|boleh\s+tutup|tutup\s+tiket|close\s+ticket|sudah\s+selesai/u',
            $t,
        );

        if ($negative && $positive) {
            return false;
        }
        if ($negative) {
            return false;
        }
        if ($positive) {
            return true;
        }

        return null;
    }

    private function buildTranscript(SupportTicket $ticket): string
    {
        $lines = [];
        $messages = $ticket->messages()->with('user:id,name')->orderBy('id')->limit(40)->get();

        foreach ($messages as $m) {
            if ($m->is_internal) {
                continue;
            }
            $who = $m->is_ai_message ? 'AINA' : ($m->user?->name ?? 'User');
            $lines[] = $who.': '.$this->flattenForTranscript((string) $m->message);
        }

        $out = implode("\n\n", $lines);

        if (mb_strlen($out) > 12000) {
            $out = mb_substr($out, -12000);
        }

        return $out;
    }

    private function flattenForTranscript(string $md): string
    {
        $t = preg_replace('/```[\s\S]*?```/', '[code]', $md) ?? $md;
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        return trim((string) $t);
    }

    /**
     * @return array{suggestion: string}|array{error: string}
     */
    public function agentAssistReplySuggestion(SupportTicket $ticket, ?string $regenerateHint = null): array
    {
        $ticket->loadMissing([
            'messages.user:id,name',
            'assignee:id,name',
        ]);

        $transcript = $this->buildTranscript($ticket);
        if ($transcript === '') {
            $transcript = '(No public messages yet — use subject/description only.)';
        }

        $assigneeName = $ticket->assignee?->name;
        $result = $this->openai->generateAgentAssistReplySuggestion(
            $transcript,
            (string) $ticket->subject,
            $ticket->module,
            $ticket->system_name,
            $assigneeName ?: null,
            $regenerateHint,
        );

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return ['suggestion' => (string) ($result['content'] ?? '')];
    }
}
