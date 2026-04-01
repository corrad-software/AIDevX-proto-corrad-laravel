<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteDatabaseRowRequest;
use App\Http\Requests\StoreDatabaseRowRequest;
use App\Http\Requests\UpdateDatabaseRowRequest;
use App\Http\Traits\ApiResponse;
use App\Services\DatabaseExplorerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DatabaseExplorerController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DatabaseExplorerService $explorer,
    ) {}

    public function tables(): JsonResponse
    {
        return $this->sendOk(['tables' => $this->explorer->listTables()]);
    }

    public function schema(Request $request, string $table): JsonResponse
    {
        try {
            $payload = $this->explorer->schemaPayload($table);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(404, 'NOT_FOUND', $e->getMessage());
        }

        return $this->sendOk($payload);
    }

    public function rows(Request $request, string $table): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 25)));
        $sortBy = $request->input('sort_by');
        $sortDir = (string) $request->input('sort_dir', 'asc');

        try {
            $result = $this->explorer->rows($table, $page, $limit, is_string($sortBy) ? $sortBy : null, $sortDir);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(404, 'NOT_FOUND', $e->getMessage());
        }

        return $this->sendOk(
            [
                'rows' => $result['rows'],
                'primary_key_columns' => $result['primary_key_columns'],
            ],
            $result['meta'],
        );
    }

    public function storeRow(StoreDatabaseRowRequest $request, string $table): JsonResponse
    {
        try {
            $row = $this->explorer->insertRow($table, $request->validated()['row']);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'VALIDATION_ERROR', $e->getMessage());
        }

        return $this->sendCreated($row);
    }

    public function updateRow(UpdateDatabaseRowRequest $request, string $table): JsonResponse
    {
        $data = $request->validated();

        try {
            $row = $this->explorer->updateRow($table, $data['primary_key'], $data['row']);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'VALIDATION_ERROR', $e->getMessage());
        }

        return $this->sendOk($row);
    }

    public function destroyRow(DeleteDatabaseRowRequest $request, string $table): JsonResponse
    {
        try {
            $this->explorer->deleteRow($table, $request->validated()['primary_key']);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'VALIDATION_ERROR', $e->getMessage());
        }

        return $this->sendOk(['success' => true]);
    }
}
