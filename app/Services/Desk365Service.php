<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Desk365Service
{
    private string $baseUrl;

    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.desk365.base_url', 'https://datasc.desk365.io/apis');
        $this->apiKey = config('services.desk365.api_key');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Ping Desk365 API server status.
     */
    public function ping(): array
    {
        return $this->get('/v3/ping');
    }

    /**
     * List all tickets with pagination.
     *
     * @param  array{page?: int, per_page?: int, status?: string, priority?: string}  $params
     */
    public function listTickets(array $params = []): array
    {
        return $this->get('/v3/tickets', $params);
    }

    /**
     * Get latest tickets (page 1, biasanya API return newest first).
     *
     * @param  int  $limit  Bilangan tiket (default 20)
     */
    public function listLatestTickets(int $limit = 20): array
    {
        $res = $this->listTickets(['page' => 1, 'per_page' => min($limit, 100)]);
        if (isset($res['error'])) {
            return $res;
        }
        $tickets = $res['tickets'] ?? $res['data'] ?? $res['results'] ?? [];

        return array_map(fn ($t) => $this->mapTicketToCsvFormat(is_array($t) ? $t : (array) $t), $tickets);
    }

    /**
     * List tickets for Support Chat. Admin: all. Agent: open/unanswered only.
     *
     * @param  array{status?: string, assigned_to?: string, limit?: int, page?: int}  $params
     */
    public function listTicketsForChat(array $params = []): array
    {
        $limit = min($params['limit'] ?? 50, 100);
        $page = $params['page'] ?? 1;
        $query = ['page' => $page, 'per_page' => $limit];
        if (! empty($params['status'])) {
            $query['status'] = $params['status'];
        }
        if (! empty($params['assigned_to'])) {
            $query['assigned_to'] = $params['assigned_to'];
        }

        $res = $this->listTickets($query);
        if (isset($res['error'])) {
            return $res;
        }

        $tickets = $res['tickets'] ?? $res['data'] ?? $res['results'] ?? [];

        return array_map(fn ($t) => $this->mapTicketToCsvFormat(is_array($t) ? $t : (array) $t), $tickets);
    }

    /**
     * Get ticket details by ID.
     */
    public function getTicketDetails(string $ticketId): array
    {
        return $this->get('/v3/tickets/details', ['ticket_id' => $ticketId]);
    }

    /**
     * Get ticket conversations.
     */
    public function getTicketConversations(string $ticketId): array
    {
        return $this->get('/v3/tickets/conversations', ['ticket_id' => $ticketId]);
    }

    /**
     * Fetch all tickets (paginated) and return flat array for CSV-like processing.
     * Maps Desk365 response to format compatible with ProcessSupportTickets.
     */
    public function fetchAllTicketsForKnowledge(int $perPage = 50): array
    {
        $all = [];
        $page = 1;

        do {
            $res = $this->listTickets(['page' => $page, 'per_page' => $perPage]);
            if (isset($res['error'])) {
                return $res;
            }
            $tickets = $res['tickets'] ?? $res['data'] ?? $res['results'] ?? [];
            if (empty($tickets)) {
                break;
            }
            foreach ($tickets as $t) {
                $all[] = $this->mapTicketToCsvFormat(is_array($t) ? $t : (array) $t);
            }
            $page++;
            $hasMore = count($tickets) >= $perPage;
        } while ($hasMore);

        return $all;
    }

    /**
     * Map Desk365 ticket to CSV-like format (Subject, Description, SubCategory, etc.).
     */
    private function mapTicketToCsvFormat(array $t): array
    {
        $assigned = $t['assigned_to'] ?? $t['assigned_to_name'] ?? $t['agent_name'] ?? $t['assigned_agent'] ?? $t['assignee'] ?? $t['assign_to'] ?? '';
        if (empty($assigned) && isset($t['assigned_to_user']['name'])) {
            $assigned = $t['assigned_to_user']['name'];
        }
        if (empty($assigned) && isset($t['assigned_to_user']['email'])) {
            $assigned = $t['assigned_to_user']['email'];
        }
        $contactName = $t['contact_name'] ?? $t['contact']['name'] ?? $t['requester'] ?? $t['created_by'] ?? $t['reporter'] ?? '';

        return [
            'Ticket Number' => $t['ticket_number'] ?? $t['id'] ?? '',
            'Subject' => $t['subject'] ?? '',
            'Description' => $t['description'] ?? '',
            'SubCategory' => $t['sub_category'] ?? $t['category'] ?? '',
            'Type' => $t['type'] ?? '',
            'Priority' => $t['priority'] ?? '',
            'Status' => $t['status'] ?? '',
            'Contact Name' => $contactName,
            'Company Name' => $t['company_name'] ?? $t['company']['name'] ?? $t['customer'] ?? $t['organization'] ?? '',
            'Created Time' => $t['created_at'] ?? $t['created_time'] ?? '',
            'Assigned Agent' => $assigned,
        ];
    }

    private function get(string $path, array $query = []): array
    {
        $this->ensureConfigured();
        $url = $this->baseUrl.$path;
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(30)->get($url, $query);

        if (! $response->successful()) {
            Log::warning('Desk365 API error', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['error' => $response->body(), 'status' => $response->status()];
        }

        return $response->json() ?? [];
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('DESK365_API_KEY not set in .env. Add DESK365_API_KEY and DESK365_BASE_URL.');
        }
    }
}
