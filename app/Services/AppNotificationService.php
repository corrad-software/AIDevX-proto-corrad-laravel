<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Mail\InAppNotificationMail;
use App\Models\Desk365SyncLog;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppNotificationService
{
    public function mailAppearsConfigured(): bool
    {
        $mailer = config('mail.default', 'log');

        return $mailer !== 'log' && $mailer !== 'array';
    }

    /**
     * Create one in-app row and optionally send email.
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(
        User $user,
        string $notificationType,
        ?string $module,
        ?string $eventKey,
        string $title,
        string $body,
        array $data = [],
        bool $sendEmail = true,
    ): InAppNotification {
        $row = InAppNotification::create([
            'user_id' => $user->id,
            'notification_type' => $notificationType,
            'module' => $module,
            'event_key' => $eventKey,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
            'email_status' => 'pending',
        ]);

        if ($sendEmail) {
            $this->sendEmailForNotification($row, $user);
        } else {
            $row->update(['email_status' => 'skipped']);
        }

        return $row->fresh();
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function notifyMany(
        iterable $userIds,
        string $notificationType,
        ?string $module,
        ?string $eventKey,
        string $title,
        string $body,
        array $data = [],
        bool $sendEmail = true,
    ): void {
        foreach ($userIds as $id) {
            $user = User::find((int) $id);
            if ($user && $user->is_active) {
                $this->notifyUser($user, $notificationType, $module, $eventKey, $title, $body, $data, $sendEmail);
            }
        }
    }

    public function sendEmailForNotification(InAppNotification $notification, ?User $user = null): void
    {
        $user = $user ?? $notification->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $notification->update(['email_status' => 'skipped', 'email_error' => 'Invalid user email']);

            return;
        }

        if (! $this->mailAppearsConfigured()) {
            $notification->update(['email_status' => 'skipped']);

            return;
        }

        try {
            Mail::to($user->email)->send(new InAppNotificationMail($notification, $user));
            $notification->update([
                'email_sent_at' => now(),
                'email_status' => 'sent',
                'email_error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('notification_email_failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
            $notification->update([
                'email_status' => 'failed',
                'email_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    public function agentAndAdminUserIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('user_level', [UserLevel::SUPER_ADMIN, UserLevel::INTERNAL_ADMIN, UserLevel::EXTERNAL_ADMIN, UserLevel::AGENT])
            ->pluck('id')
            ->all();
    }

    public function notifyDesk365NewTickets(?Desk365SyncLog $syncLog, array $ticketDetails, array $ticketNumbers): void
    {
        if (! $syncLog || empty($ticketNumbers)) {
            return;
        }

        $previous = Desk365SyncLog::query()
            ->where('id', '<', $syncLog->id)
            ->orderByDesc('id')
            ->first();

        $prevFlip = array_flip($previous?->uploaded_ticket_numbers ?? []);

        if (! $previous) {
            $title = 'Desk365 tickets synced';
            $body = count($ticketNumbers).' tickets were imported. Open Tickets in AFSA to review.';
            $this->notifyMany(
                $this->agentAndAdminUserIds(),
                'system',
                'ticket',
                'ticket.sync_initial',
                $title,
                $body,
                ['count' => count($ticketNumbers)],
                true
            );

            return;
        }

        foreach ($ticketDetails as $t) {
            $num = (string) ($t['ticket_number'] ?? '');
            if ($num === '' || isset($prevFlip[$num])) {
                continue;
            }

            $subject = (string) ($t['subject'] ?? 'No subject');
            $title = 'New ticket #'.$num;
            $body = $subject;
            $assigned = (string) ($t['assigned_agent'] ?? '');
            $targetId = $this->resolveAssignedUserId($assigned);
            $ids = $targetId !== null ? [$targetId] : $this->agentAndAdminUserIds();

            $this->notifyMany(
                $ids,
                'system',
                'ticket',
                'ticket.new',
                $title,
                $body,
                array_merge($t, ['ticket_number' => $num]),
                true
            );
        }
    }

    private function resolveAssignedUserId(string $assigned): ?int
    {
        $assigned = trim($assigned);
        if ($assigned === '') {
            return null;
        }
        $lower = strtolower($assigned);
        $byEmail = User::whereRaw('LOWER(email) = ?', [$lower])->first();
        if ($byEmail) {
            return $byEmail->id;
        }
        $byName = User::whereRaw('LOWER(name) = ?', [$lower])->first();
        if ($byName) {
            return $byName->id;
        }
        if (str_contains($assigned, '@')) {
            $part = strtolower(trim(explode('@', $assigned)[0]));
            $guess = User::whereRaw('LOWER(email) LIKE ?', [$part.'@%'])->first();

            return $guess?->id;
        }

        return null;
    }

    public function notifyUserCreated(User $newUser, ?User $actor = null): void
    {
        $this->notifyUser(
            $newUser,
            'user',
            'auth',
            'user.created',
            'Your account was created',
            'You can sign in with the email and password provided by your administrator.',
            ['created_by' => $actor?->id],
            true
        );

        $adminIds = User::query()
            ->where('is_active', true)
            ->whereIn('user_level', [UserLevel::SUPER_ADMIN, UserLevel::INTERNAL_ADMIN, UserLevel::EXTERNAL_ADMIN])
            ->where('id', '!=', $newUser->id)
            ->pluck('id')
            ->all();

        $actorLabel = $actor ? $actor->name : 'System';
        $this->notifyMany(
            $adminIds,
            'system',
            'auth',
            'user.created_admin',
            'New user: '.$newUser->name,
            $actorLabel.' created an account for '.$newUser->email,
            ['user_id' => $newUser->id, 'email' => $newUser->email],
            true
        );
    }

    public function notifyGroupChatInvite(User $invitedUser, User $inviter, int $sessionId, string $sessionTitle = ''): void
    {
        $title = 'Added to a group chat';
        $body = $inviter->name.' added you to a support chat session.'.($sessionTitle ? ' '.$sessionTitle : '');
        $this->notifyUser(
            $invitedUser,
            'user',
            'chat',
            'chat.group_invite',
            $title,
            $body,
            [
                'session_id' => $sessionId,
                'inviter_id' => $inviter->id,
            ],
            true
        );
    }
}
