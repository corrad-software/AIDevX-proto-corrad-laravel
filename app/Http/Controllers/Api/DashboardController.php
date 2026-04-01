<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ChatSession;
use App\Models\InAppNotification;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Desk365Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected Desk365Service $desk365,
    ) {}

    /**
     * Return summary counts and recent posts/pages.
     * Level-specific: L0 platform, L1/L2 pentadbir sokongan, L3 ejen, L4 pengguna.
     */
    public function summary(): JsonResponse
    {
        $user = Auth::user();
        $userLevel = UserLevel::normalize($user->user_level ?? UserLevel::USER);

        $counts = [
            'posts' => Post::count(),
            'pages' => Page::count(),
            'media' => Media::count(),
            'users' => User::count(),
        ];

        $recentPosts = Post::orderBy('updated_at', 'desc')->take(5)->get();
        $recentPages = Page::orderBy('updated_at', 'desc')->take(5)->get();

        $support = null;
        if (UserLevel::canAccessSupportChat($userLevel) || $user->hasPermission(Permission::CHAT_ADMIN)) {
            $support = $this->buildSupportSummary($user, $userLevel);
        } elseif (UserLevel::isEndUserTier($userLevel)) {
            $support = $this->buildUserTicketSummary($user);
        }

        $unreadNotifications = InAppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return $this->sendOk([
            'userLevel' => $userLevel,
            'counts' => $counts,
            'recent' => [
                'posts' => $recentPosts,
                'pages' => $recentPages,
            ],
            'support' => $support,
            'unread_notifications' => $unreadNotifications,
            'posts_by_year' => $this->postsPublishedCountByYear(),
        ]);
    }

    /**
     * Published posts grouped by calendar year of {@see Post::$published_at}.
     *
     * @return list<array{year: int, count: int}>
     */
    private function postsPublishedCountByYear(): array
    {
        $driver = DB::connection()->getDriverName();
        $q = Post::query()
            ->where('status', 'published')
            ->whereNotNull('published_at');

        if ($driver === 'sqlite') {
            $expr = "strftime('%Y', published_at)";
            $rows = (clone $q)
                ->selectRaw("{$expr} as y, COUNT(*) as c")
                ->groupBy(DB::raw($expr))
                ->orderBy('y')
                ->get();
        } else {
            $rows = (clone $q)
                ->selectRaw('YEAR(published_at) as y, COUNT(*) as c')
                ->groupBy(DB::raw('YEAR(published_at)'))
                ->orderBy('y')
                ->get();
        }

        return $rows->map(fn ($r) => [
            'year' => (int) $r->y,
            'count' => (int) $r->c,
        ])->values()->all();
    }

    private function buildSupportSummary($user, string $userLevel): array
    {
        $ticketCount = null;
        if ($this->desk365->isConfigured()) {
            $params = ['limit' => 200];
            $tickets = $this->desk365->listTicketsForChat($params);
            if (! isset($tickets['error']) && is_array($tickets)) {
                $seeAll = UserLevel::canSeeAllDeskTickets($userLevel)
                    || $user->hasPermission(Permission::CHAT_ADMIN);
                if (! $seeAll) {
                    $userName = strtolower(trim($user->name ?? ''));
                    $userEmail = strtolower(trim($user->email ?? ''));
                    $tickets = array_values(array_filter($tickets, function ($t) use ($userName, $userEmail) {
                        if (($t['Status'] ?? '') === 'closed') {
                            return false;
                        }
                        $assigned = strtolower(trim((string) ($t['Assigned Agent'] ?? '')));
                        if ($assigned === '') {
                            return false;
                        }
                        if ($assigned === $userEmail || $assigned === $userName) {
                            return true;
                        }
                        if ($userEmail && str_contains($assigned, $userEmail)) {
                            return true;
                        }
                        if ($userName && (str_contains($assigned, $userName) || str_starts_with($assigned, $userName))) {
                            return true;
                        }

                        return false;
                    }));
                } else {
                    $tickets = array_values(array_filter($tickets, fn ($t) => ($t['Status'] ?? '') !== 'closed'));
                }
                $ticketCount = count($tickets);
            }
        }

        return [
            'ticketCount' => $ticketCount,
        ];
    }

    /**
     * L4 requestor summary: own Desk365 + own internal ticket counts.
     */
    private function buildUserTicketSummary($user): array
    {
        $internalTicketCount = SupportTicket::query()
            ->where('created_by_user_id', $user->id)
            ->where('status', '!=', 'closed')
            ->count();

        $desk365TicketCount = null;
        if ($this->desk365->isConfigured()) {
            $tickets = $this->desk365->listTicketsForChat(['limit' => 200]);
            if (! isset($tickets['error']) && is_array($tickets)) {
                $email = strtolower(trim((string) $user->email));
                $name = strtolower(trim((string) $user->name));
                $filtered = array_values(array_filter($tickets, function ($t) use ($email, $name) {
                    if (($t['Status'] ?? '') === 'closed') {
                        return false;
                    }
                    $requestor = strtolower(trim((string) ($t['Contact Name'] ?? $t['Requester'] ?? $t['Requester Name'] ?? '')));
                    $requestorEmail = strtolower(trim((string) ($t['Contact Email'] ?? $t['Requester Email'] ?? $t['Email'] ?? '')));

                    if ($email !== '' && ($requestorEmail === $email || str_contains($requestorEmail, $email))) {
                        return true;
                    }

                    if ($name !== '' && ($requestor === $name || str_contains($requestor, $name))) {
                        return true;
                    }

                    return false;
                }));
                $desk365TicketCount = count($filtered);
            }
        }

        return [
            'ticketCount' => $desk365TicketCount,
            'desk365TicketCount' => $desk365TicketCount,
            'internalTicketCount' => $internalTicketCount,
        ];
    }

    /**
     * Admin/Agent analytics: tickets by agent, by module, chat session stats.
     */
    public function analytics(): JsonResponse
    {
        $user = Auth::user();
        $userLevel = UserLevel::normalize($user->user_level ?? UserLevel::USER);

        if (! UserLevel::canAccessSupportChat($userLevel) && ! $user->hasPermission(Permission::CHAT_ADMIN)) {
            return $this->sendOk([
                'ticketsByAgent' => [],
                'ticketsByModule' => [],
                'internalTicketsByAgent' => [],
                'internalTicketsByModule' => [],
                'chatSessionsByUser' => [],
                'topAgents' => [],
                'newTickets' => [],
            ]);
        }

        $ticketsByAgent = [];
        $ticketsByModule = [];
        $internalTicketsByAgent = [];
        $internalTicketsByModule = [];
        $newTickets = [];

        $seeAll = UserLevel::canSeeAllDeskTickets($userLevel)
            || $user->hasPermission(Permission::CHAT_ADMIN);

        if ($this->desk365->isConfigured()) {
            $tickets = $this->desk365->listTicketsForChat(['limit' => 500]);
            if (! isset($tickets['error']) && is_array($tickets)) {
                if (! $seeAll) {
                    $userName = strtolower(trim($user->name ?? ''));
                    $userEmail = strtolower(trim($user->email ?? ''));
                    $tickets = array_values(array_filter($tickets, function ($t) use ($userName, $userEmail) {
                        if (($t['Status'] ?? '') === 'closed') {
                            return false;
                        }
                        $assigned = strtolower(trim((string) ($t['Assigned Agent'] ?? '')));
                        if ($assigned === '') {
                            return false;
                        }
                        if ($assigned === $userEmail || $assigned === $userName) {
                            return true;
                        }
                        if ($userEmail && str_contains($assigned, $userEmail)) {
                            return true;
                        }
                        if ($userName && (str_contains($assigned, $userName) || str_starts_with($assigned, $userName))) {
                            return true;
                        }

                        return false;
                    }));
                } else {
                    $tickets = array_values(array_filter($tickets, fn ($t) => ($t['Status'] ?? '') !== 'closed'));
                }

                foreach ($tickets as $t) {
                    $agent = trim((string) ($t['Assigned Agent'] ?? ''));
                    $module = trim((string) ($t['SubCategory'] ?? $t['Type'] ?? 'Uncategorized'));
                    if ($module === '') {
                        $module = 'Uncategorized';
                    }
                    $ticketsByAgent[$agent] = ($ticketsByAgent[$agent] ?? 0) + 1;
                    $ticketsByModule[$module] = ($ticketsByModule[$module] ?? 0) + 1;
                }

                $newTickets = array_slice($tickets, 0, 10);
            }
        }

        $internalQuery = SupportTicket::query()
            ->with(['assignee:id,name,email'])
            ->where('status', '!=', 'closed');
        if (! $seeAll) {
            $internalQuery->where('assigned_to_user_id', $user->id);
        }
        $internalTickets = $internalQuery->get();
        foreach ($internalTickets as $ticket) {
            $agent = trim((string) ($ticket->assignee?->email ?: $ticket->assignee?->name ?: 'Unassigned'));
            $module = trim((string) ($ticket->module ?: 'Uncategorized'));
            $internalTicketsByAgent[$agent] = ($internalTicketsByAgent[$agent] ?? 0) + 1;
            $internalTicketsByModule[$module] = ($internalTicketsByModule[$module] ?? 0) + 1;
        }

        $topAgents = collect($ticketsByAgent)->sortDesc()->take(10)->map(fn ($v, $k) => ['agent' => $k, 'count' => $v])->values()->all();

        $chatSessionsByUser = [];
        if ($seeAll) {
            $sessions = ChatSession::selectRaw('user_id, count(*) as cnt')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->get();
            $userIds = $sessions->pluck('user_id')->unique()->all();
            $users = User::whereIn('id', $userIds)->get()->keyBy('id');
            foreach ($sessions as $s) {
                $name = $users->get($s->user_id)?->name ?? 'Unknown';
                $chatSessionsByUser[] = ['user' => $name, 'count' => (int) $s->cnt];
            }
            usort($chatSessionsByUser, fn ($a, $b) => $b['count'] <=> $a['count']);
            $chatSessionsByUser = array_slice($chatSessionsByUser, 0, 10);
        }

        return $this->sendOk([
            'ticketsByAgent' => $ticketsByAgent,
            'ticketsByModule' => $ticketsByModule,
            'internalTicketsByAgent' => $internalTicketsByAgent,
            'internalTicketsByModule' => $internalTicketsByModule,
            'chatSessionsByUser' => $chatSessionsByUser,
            'topAgents' => $topAgents,
            'newTickets' => $newTickets,
        ]);
    }
}
