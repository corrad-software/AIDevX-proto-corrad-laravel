<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDatabaseTableRequest;
use App\Http\Requests\UpsertDatabaseRowRequest;
use App\Http\Traits\ApiResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DatabaseEditorController extends Controller
{
    use ApiResponse;

    public function listTables(): JsonResponse
    {
        return $this->sendOk([
            'tables' => $this->getTables(),
            'driver' => DB::getDriverName(),
            'database' => DB::getDatabaseName(),
        ]);
    }

    public function tableSchema(string $table): JsonResponse
    {
        try {
            $table = $this->validatedTable($table);
            $columns = $this->getColumns($table);

            return $this->sendOk([
                'table' => $table,
                'primaryKey' => collect($columns)->firstWhere('isPrimary', true)['name'] ?? null,
                'columns' => $columns,
                'rowCount' => DB::table($table)->count(),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    public function tableRows(Request $request, string $table): JsonResponse
    {
        try {
            $table = $this->validatedTable($table);
            $columns = $this->getColumns($table);
            $primaryKey = collect($columns)->firstWhere('isPrimary', true)['name'] ?? null;
            $page = max(1, (int) $request->integer('page', 1));
            $limit = min(200, max(1, (int) $request->integer('limit', 25)));
            $offset = ($page - 1) * $limit;

            $query = DB::table($table);
            if ($primaryKey && collect($columns)->pluck('name')->contains($primaryKey)) {
                $query->orderBy($primaryKey, 'desc');
            }

            $rows = $query->offset($offset)->limit($limit)->get()->map(function ($row) {
                return (array) $row;
            })->all();

            return $this->sendOk([
                'table' => $table,
                'page' => $page,
                'limit' => $limit,
                'total' => DB::table($table)->count(),
                'rows' => $rows,
                'primaryKey' => $primaryKey,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    public function createRow(UpsertDatabaseRowRequest $request, string $table): JsonResponse
    {
        try {
            $table = $this->validatedTable($table);
            $columns = $this->getColumns($table);
            $payload = $this->sanitizeRowPayload($request->validated()['data'], $columns, true);
            DB::table($table)->insert($payload);

            return $this->sendOk([
                'message' => 'Row created successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    public function updateRow(UpsertDatabaseRowRequest $request, string $table, string $rowId): JsonResponse
    {
        try {
            $table = $this->validatedTable($table);
            $columns = $this->getColumns($table);
            $primaryKey = collect($columns)->firstWhere('isPrimary', true)['name'] ?? null;
            if (! $primaryKey) {
                throw new InvalidArgumentException('Table does not have a primary key.');
            }

            $payload = $this->sanitizeRowPayload($request->validated()['data'], $columns, false);
            $updated = DB::table($table)->where($primaryKey, $rowId)->update($payload);
            if ($updated === 0) {
                throw new InvalidArgumentException('Row not found or no changes detected.');
            }

            return $this->sendOk([
                'message' => 'Row updated successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    public function deleteRow(string $table, string $rowId): JsonResponse
    {
        try {
            $table = $this->validatedTable($table);
            $columns = $this->getColumns($table);
            $primaryKey = collect($columns)->firstWhere('isPrimary', true)['name'] ?? null;
            if (! $primaryKey) {
                throw new InvalidArgumentException('Table does not have a primary key.');
            }

            $deleted = DB::table($table)->where($primaryKey, $rowId)->delete();
            if ($deleted === 0) {
                throw new InvalidArgumentException('Row not found.');
            }

            return $this->sendOk([
                'message' => 'Row deleted successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    public function createTable(CreateDatabaseTableRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $tableName = $validated['table'];
            if (Schema::hasTable($tableName)) {
                throw new InvalidArgumentException("Table {$tableName} already exists.");
            }

            $columns = collect($validated['columns']);
            $primaryKey = $validated['primaryKey'] ?? null;
            $withTimestamps = $validated['withTimestamps'] ?? true;
            if ($primaryKey && ! $columns->pluck('name')->contains($primaryKey)) {
                throw new InvalidArgumentException('Primary key must be one of the submitted columns.');
            }

            Schema::create($tableName, function (Blueprint $table) use ($columns, $primaryKey, $withTimestamps) {
                foreach ($columns as $column) {
                    $name = $column['name'];
                    $type = $column['type'];
                    $nullable = (bool) ($column['nullable'] ?? false);
                    $definition = $this->createColumnDefinition($table, $type, $name);
                    if ($nullable) {
                        $definition->nullable();
                    }
                }

                if ($primaryKey) {
                    $table->primary($primaryKey);
                }

                if ($withTimestamps) {
                    $table->timestamps();
                }
            });

            return $this->sendOk([
                'message' => "Table {$tableName} created successfully.",
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    public function dropTable(string $table): JsonResponse
    {
        try {
            $table = $this->validatedTable($table);
            Schema::drop($table);

            return $this->sendOk([
                'message' => "Table {$table} dropped successfully.",
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->sendError(422, 'DB_EDITOR_INVALID_INPUT', $e->getMessage());
        }
    }

    private function createColumnDefinition(Blueprint $table, string $type, string $name): \Illuminate\Database\Schema\ColumnDefinition
    {
        return match ($type) {
            'string' => $table->string($name),
            'text' => $table->text($name),
            'longText' => $table->longText($name),
            'integer' => $table->integer($name),
            'bigInteger' => $table->bigInteger($name),
            'boolean' => $table->boolean($name),
            'dateTime' => $table->dateTime($name),
            'json' => $table->json($name),
            default => throw new InvalidArgumentException("Unsupported type: {$type}"),
        };
    }

    private function validatedTable(string $table): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }

        if (! Schema::hasTable($table)) {
            throw new InvalidArgumentException("Table {$table} not found.");
        }

        return $table;
    }

    private function getTables(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $dbName = DB::getDatabaseName();
            $tables = DB::select('SHOW TABLES');
            $field = 'Tables_in_'.$dbName;

            return collect($tables)
                ->map(fn ($table) => $table->{$field} ?? null)
                ->filter()
                ->values()
                ->all();
        }

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->values()
                ->all();
        }

        return Schema::getTableListing();
    }

    private function getColumns(string $table): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW COLUMNS FROM `{$table}`");

            return collect($rows)->map(function ($row) {
                return [
                    'name' => $row->Field,
                    'type' => $row->Type,
                    'nullable' => $row->Null === 'YES',
                    'default' => $row->Default,
                    'isPrimary' => $row->Key === 'PRI',
                    'extra' => $row->Extra,
                ];
            })->all();
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA table_info('{$table}')");

            return collect($rows)->map(function ($row) {
                return [
                    'name' => $row->name,
                    'type' => $row->type,
                    'nullable' => (int) $row->notnull === 0,
                    'default' => $row->dflt_value,
                    'isPrimary' => (int) $row->pk === 1,
                    'extra' => null,
                ];
            })->all();
        }

        return collect(Schema::getColumns($table))->map(function ($col) {
            return [
                'name' => $col['name'],
                'type' => $col['type_name'] ?? 'unknown',
                'nullable' => (bool) ($col['nullable'] ?? true),
                'default' => $col['default'] ?? null,
                'isPrimary' => false,
                'extra' => null,
            ];
        })->all();
    }

    private function sanitizeRowPayload(array $payload, array $columns, bool $isCreate): array
    {
        $columnMap = collect($columns)->keyBy('name');
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (! $columnMap->has($key)) {
                continue;
            }

            $meta = $columnMap->get($key);
            if ($isCreate && ($meta['isPrimary'] ?? false) && str_contains((string) ($meta['extra'] ?? ''), 'auto_increment')) {
                continue;
            }

            $filtered[$key] = $this->castValueByType($value, (string) ($meta['type'] ?? ''));
        }

        if ($filtered === []) {
            throw new InvalidArgumentException('No valid columns submitted.');
        }

        return $filtered;
    }

    private function castValueByType(mixed $value, string $dbType): mixed
    {
        if ($value === '') {
            return null;
        }

        $normalized = strtolower($dbType);
        if (str_contains($normalized, 'int')) {
            return is_numeric($value) ? (int) $value : $value;
        }

        if (str_contains($normalized, 'bool') || str_contains($normalized, 'tinyint(1)')) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
        }

        if (str_contains($normalized, 'json') && is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}
