<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseExplorerService
{
    /** Tables where INSERT/UPDATE/DELETE are blocked. */
    private const WRITE_BLOCKLIST = ['migrations'];

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            return array_map(fn ($r) => $r->name, $rows);
        }

        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT TABLE_NAME as name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            [$db]
        );

        return array_map(fn ($r) => $r->name, $rows);
    }

    public function assertTableExists(string $table): void
    {
        $this->assertIdentifier($table);
        if (! in_array($table, $this->listTables(), true)) {
            throw new InvalidArgumentException('Table not found');
        }
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>
     */
    public function describeColumns(string $table): array
    {
        $this->assertTableExists($table);

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $quoted = $this->quoteSQLiteIdent($table);
            $cols = DB::select("PRAGMA table_info({$quoted})");

            return array_map(function ($c) {
                return [
                    'name' => $c->name,
                    'type' => $c->type,
                    'nullable' => ! (bool) $c->notnull,
                    'default' => $c->dflt_value,
                    'primaryKey' => (bool) $c->pk,
                ];
            }, $cols);
        }

        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT COLUMN_NAME as name, DATA_TYPE as data_type, IS_NULLABLE as is_nullable, COLUMN_KEY as col_key, COLUMN_DEFAULT as col_default
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$db, $table]
        );

        return array_map(function ($r) {
            return [
                'name' => $r->name,
                'type' => $r->data_type,
                'nullable' => strtoupper((string) $r->is_nullable) === 'YES',
                'default' => $r->col_default,
                'primaryKey' => ($r->col_key === 'PRI'),
            ];
        }, $rows);
    }

    /**
     * @return array{columns: array, primary_key_columns: array}
     */
    public function schemaPayload(string $table): array
    {
        $columns = $this->describeColumns($table);

        return [
            'columns' => $columns,
            'primary_key_columns' => $this->primaryKeyColumnsFromColumns($columns),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, primary_key_columns: array<int, string>, meta: array{page: int, limit: int, total: int, total_pages: int}}
     */
    public function rows(string $table, int $page, int $limit, ?string $sortBy, string $sortDir): array
    {
        $this->assertTableExists($table);
        $columns = $this->describeColumns($table);
        $colNames = array_column($columns, 'name');
        $pk = $this->primaryKeyColumnsFromColumns($columns);

        $orderCol = ($sortBy && in_array($sortBy, $colNames, true))
            ? $sortBy
            : ($pk[0] ?? $colNames[0] ?? null);

        if ($orderCol === null) {
            throw new InvalidArgumentException('Table has no columns');
        }

        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
        $query = DB::table($table);

        $total = (clone $query)->count();
        $offset = max(0, ($page - 1) * $limit);
        $items = $query->orderBy($orderCol, $sortDir)->offset($offset)->limit($limit)->get();

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->normalizeRowForJson((array) $item);
        }

        return [
            'rows' => $rows,
            'primary_key_columns' => $pk,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function insertRow(string $table, array $row): array
    {
        $this->assertWritable($table);
        $columns = $this->describeColumns($table);
        $payload = $this->filterRowToColumns($row, $columns);

        try {
            DB::table($table)->insert($payload);
        } catch (QueryException $e) {
            throw new InvalidArgumentException('Could not insert row: '.$e->getMessage(), 0, $e);
        }

        return $this->fetchRowByPrimaryKey($table, $columns, $payload);
    }

    /**
     * @param  array<string, mixed>  $primaryKey
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function updateRow(string $table, array $primaryKey, array $row): array
    {
        $this->assertWritable($table);
        $columns = $this->describeColumns($table);
        $pkCols = $this->primaryKeyColumnsFromColumns($columns);
        if ($pkCols === []) {
            throw new InvalidArgumentException('Table has no primary key; update is not supported');
        }
        $this->assertPrimaryKeyMatch($primaryKey, $pkCols);

        $payload = $this->filterRowToColumns($row, $columns);
        foreach ($pkCols as $c) {
            unset($payload[$c]);
        }

        $q = DB::table($table);
        foreach ($pkCols as $col) {
            $q->where($col, $primaryKey[$col]);
        }

        try {
            if ($payload !== []) {
                $q->update($payload);
            }
        } catch (QueryException $e) {
            throw new InvalidArgumentException('Could not update row: '.$e->getMessage(), 0, $e);
        }

        $fresh = DB::table($table);
        foreach ($pkCols as $col) {
            $fresh->where($col, $primaryKey[$col]);
        }
        $found = $fresh->first();
        if (! $found) {
            throw new InvalidArgumentException('Row not found after update');
        }

        return $this->normalizeRowForJson((array) $found);
    }

    /**
     * @param  array<string, mixed>  $primaryKey
     */
    public function deleteRow(string $table, array $primaryKey): void
    {
        $this->assertWritable($table);
        $columns = $this->describeColumns($table);
        $pkCols = $this->primaryKeyColumnsFromColumns($columns);
        if ($pkCols === []) {
            throw new InvalidArgumentException('Table has no primary key; delete is not supported');
        }
        $this->assertPrimaryKeyMatch($primaryKey, $pkCols);

        $q = DB::table($table);
        foreach ($pkCols as $col) {
            $q->where($col, $primaryKey[$col]);
        }

        try {
            $deleted = $q->delete();
        } catch (QueryException $e) {
            throw new InvalidArgumentException('Could not delete row: '.$e->getMessage(), 0, $e);
        }

        if ($deleted === 0) {
            throw new InvalidArgumentException('Row not found');
        }
    }

    private function assertWritable(string $table): void
    {
        $this->assertTableExists($table);
        if (in_array($table, self::WRITE_BLOCKLIST, true)) {
            throw new InvalidArgumentException('Writes to this table are not allowed');
        }
    }

    private function assertIdentifier(string $name): void
    {
        if ($name === '' || ! preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid table name');
        }
    }

    private function quoteSQLiteIdent(string $table): string
    {
        return '"'.str_replace('"', '""', $table).'"';
    }

    /**
     * @param  array<int, array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>  $columns
     * @return array<int, string>
     */
    private function primaryKeyColumnsFromColumns(array $columns): array
    {
        return array_values(array_map(
            fn ($c) => $c['name'],
            array_filter($columns, fn ($c) => $c['primaryKey'])
        ));
    }

    /**
     * @param  array<int, array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>  $columns
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function filterRowToColumns(array $row, array $columns): array
    {
        $names = array_column($columns, 'name');
        $out = [];
        foreach ($row as $k => $v) {
            if (! in_array($k, $names, true)) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $pkCols
     * @param  array<string, mixed>  $primaryKey
     */
    private function assertPrimaryKeyMatch(array $primaryKey, array $pkCols): void
    {
        foreach ($pkCols as $col) {
            if (! array_key_exists($col, $primaryKey)) {
                throw new InvalidArgumentException('Missing primary key column: '.$col);
            }
        }
    }

    /**
     * @param  array<int, array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>  $columns
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fetchRowByPrimaryKey(string $table, array $columns, array $payload): array
    {
        $pkCols = $this->primaryKeyColumnsFromColumns($columns);
        $query = DB::table($table);

        if ($pkCols !== []) {
            $haveAll = true;
            foreach ($pkCols as $col) {
                if (! array_key_exists($col, $payload)) {
                    $haveAll = false;
                    break;
                }
                $query->where($col, $payload[$col]);
            }
            if ($haveAll) {
                $found = $query->first();
                if ($found) {
                    return $this->normalizeRowForJson((array) $found);
                }
            }
        }

        $lastId = DB::getPdo()->lastInsertId();
        if ($lastId !== false && $lastId !== '0' && count($pkCols) === 1) {
            $found = DB::table($table)->where($pkCols[0], $lastId)->first();
            if ($found) {
                return $this->normalizeRowForJson((array) $found);
            }
        }

        return $this->normalizeRowForJson($payload);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRowForJson(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_resource($v)) {
                $row[$k] = null;
            }
        }

        return $row;
    }
}
