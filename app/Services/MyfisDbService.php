<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MyfisDbService
{
    private ?\PDO $conn = null;

    private function connect(): \PDO
    {
        if ($this->conn) {
            return $this->conn;
        }

        $this->ensureTunnel();

        $this->conn = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('MYFIS_DB_HOST', '127.0.0.1'),
                env('MYFIS_DB_PORT', '3307'),
                env('MYFIS_DB_USR', 'fims_usr')
            ),
            env('MYFIS_DB_USERNAME', 'admin'),
            env('MYFIS_DB_PASSWORD', ''),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 10,
            ]
        );

        return $this->conn;
    }

    public function query(string $sql, int $limit = 50): array
    {
        // Safety: READ-ONLY — block any mutating statements
        $upperSql = strtoupper(trim($sql));
        $forbidden = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'REPLACE', 'GRANT', 'REVOKE'];
        foreach ($forbidden as $keyword) {
            if (str_starts_with($upperSql, $keyword)) {
                return ['error' => "Query tidak dibenarkan: {$keyword} tidak diizinkan. Hanya SELECT sahaja."];
            }
        }

        if (! str_starts_with($upperSql, 'SELECT') && ! str_starts_with($upperSql, 'SHOW') && ! str_starts_with($upperSql, 'DESCRIBE')) {
            return ['error' => 'Hanya SELECT, SHOW, atau DESCRIBE query dibenarkan.'];
        }

        try {
            $conn = $this->connect();

            // Inject LIMIT if not present
            if (! str_contains($upperSql, 'LIMIT')) {
                $sql = rtrim($sql, '; ')." LIMIT {$limit}";
            }

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            return [
                'rows' => $rows,
                'count' => count($rows),
                'sql' => $sql,
            ];
        } catch (\Exception $e) {
            Log::error('MyfisDbService query error: '.$e->getMessage(), ['sql' => $sql]);

            return ['error' => 'Query gagal: '.$e->getMessage()];
        }
    }

    public function getTables(): array
    {
        try {
            $conn = $this->connect();

            return $conn->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function describeTable(string $table): array
    {
        try {
            $conn = $this->connect();
            $stmt = $conn->prepare('DESCRIBE '.$table);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function isConnected(): bool
    {
        try {
            $this->connect();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function ensureTunnel(): void
    {
        $port = env('MYFIS_DB_PORT', '3307');
        $check = shell_exec("lsof -i :{$port} 2>/dev/null | grep LISTEN");

        if ($check) {
            return;
        }

        $host = env('MYFIS_BASTION_HOST');
        $user = env('MYFIS_BASTION_USER', 'ec2-user');
        $key = env('MYFIS_BASTION_KEY');
        $dbHost = env('MYFIS_DB_INTERNAL_HOST');

        if (! $host || ! $key) {
            throw new \Exception('MYFIS bastion config not set in .env');
        }

        $cmd = "ssh -i {$key} -o StrictHostKeyChecking=no -o ConnectTimeout=10 -f -N -L {$port}:{$dbHost}:3306 {$user}@{$host} 2>/dev/null";
        shell_exec($cmd);
        sleep(2);

        // Verify
        $check = shell_exec("lsof -i :{$port} 2>/dev/null | grep LISTEN");
        if (! $check) {
            throw new \Exception('SSH tunnel failed to establish');
        }
    }
}
