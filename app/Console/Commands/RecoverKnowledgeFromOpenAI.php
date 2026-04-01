<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\OpenAIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecoverKnowledgeFromOpenAI extends Command
{
    protected $signature = 'kerisi:recover-knowledge-from-openai
                            {--list : Senarai fail dalam Vector Store sahaja (tiada muat turun)}
                            {--path=knowledge-recovery : Subfolder di bawah storage/app untuk fail yang dimuat turun}
                            {--import : Selepas muat turun, cipta semula rekod knowledge_documents (fail ke storage/app/public/knowledge)}';

    protected $description = 'Pulihkan fail knowledge dari OpenAI Vector Store (API) — senarai, muat turun, import DB';

    public function handle(OpenAIService $openAI): int
    {
        $apiKey = config('services.openai.key');
        $vsId = config('services.openai.vector_store_id');

        if (empty($apiKey)) {
            $this->error('OPENAI_API_KEY tidak ditetapkan dalam .env');

            return 1;
        }
        if (empty($vsId)) {
            $this->error('OPENAI_VECTOR_STORE_ID tidak ditetapkan dalam .env');
            $this->line('Jika perlu, semak di https://platform.openai.com/storage/vector_stores atau jalankan POST /api/knowledge/setup (autentikasi).');

            return 1;
        }

        $this->info("Vector Store: {$vsId}");
        $this->newLine();

        $list = $openAI->listAllVectorStoreFiles();
        if (isset($list['error'])) {
            $msg = $list['error']['message'] ?? json_encode($list['error']);
            $this->error('Gagal menyenarai fail: '.$msg);

            return 1;
        }

        $rows = $list['data'] ?? [];
        if ($rows === []) {
            $this->warn('Tiada fail dalam Vector Store ini (atau API kosong).');
            $this->line('Paparan web: https://platform.openai.com/storage/vector_stores — pilih store yang sama dengan OPENAI_VECTOR_STORE_ID.');

            return 0;
        }

        $this->info('Jumlah fail dalam Vector Store: '.count($rows));

        foreach ($rows as $i => $row) {
            $fid = $this->resolveOpenAiFileId($row);
            $label = $fid ?? json_encode($row);
            $this->line(sprintf('  [%d] %s', $i + 1, $label));
        }

        if ($this->option('list')) {
            $this->newLine();
            $this->comment('Tamat (mod --list). Buang --list untuk muat turun + optional --import.');

            return 0;
        }

        $relBase = trim($this->option('path'), '/');
        $localBase = storage_path('app/'.$relBase);
        if (! is_dir($localBase)) {
            mkdir($localBase, 0755, true);
        }

        $import = (bool) $this->option('import');
        $uploaderId = User::orderBy('id')->value('id');

        $processed = 0;
        $skipped = 0;
        $fail = 0;

        foreach ($rows as $row) {
            $fileId = $this->resolveOpenAiFileId($row);
            if (! $fileId) {
                $this->warn('Langkau baris tanpa file id: '.json_encode($row));
                $fail++;

                continue;
            }

            if ($import && KnowledgeDocument::where('openai_file_id', $fileId)->exists()) {
                $this->line("Sudah ada dalam DB (openai_file_id={$fileId}), langkau.");
                $skipped++;

                continue;
            }

            $meta = $openAI->retrieveFileMetadata($fileId);
            if (isset($meta['error'])) {
                $this->error("Metadata {$fileId}: ".($meta['error']['message'] ?? json_encode($meta['error'])));
                $fail++;

                continue;
            }

            $filename = (string) ($meta['filename'] ?? 'file-'.$fileId);
            $bytes = (int) ($meta['bytes'] ?? 0);

            $dl = $openAI->downloadFileContent($fileId);
            if (! ($dl['ok'] ?? false)) {
                $this->error("Muat turun gagal {$fileId}: ".($dl['error'] ?? 'unknown'));
                $fail++;

                continue;
            }

            $body = $dl['body'] ?? '';
            $safeLocal = strtolower(preg_replace('/[^a-zA-Z0-9.\-_]/', '-', $filename));
            if ($safeLocal === '' || $safeLocal === '-') {
                $safeLocal = $fileId.'.bin';
            }

            $localPath = $localBase.'/'.$safeLocal;
            file_put_contents($localPath, $body);
            $this->info("Disimpan: {$localPath} ({$bytes} bytes)");

            if ($import) {
                $publicName = strtolower(preg_replace('/[^a-zA-Z0-9.\-_]/', '-', $filename));
                if ($publicName === '' || $publicName === '-') {
                    $publicName = $fileId.'.bin';
                }
                $unique = $this->uniquePublicKnowledgeFilename($publicName);
                $publicRel = 'knowledge/'.$unique;
                Storage::disk('public')->put($publicRel, $body);

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'txt');
                KnowledgeDocument::create([
                    'name' => pathinfo($filename, PATHINFO_FILENAME) ?: $unique,
                    'original_filename' => $filename,
                    'file_path' => $publicRel,
                    'file_type' => $ext,
                    'file_size' => strlen($body),
                    'module' => null,
                    'openai_file_id' => $fileId,
                    'status' => 'uploaded',
                    'notes' => 'Recovered from OpenAI vector store via kerisi:recover-knowledge-from-openai',
                    'uploaded_by' => $uploaderId,
                ]);
                $this->info("Import DB: knowledge_documents (openai_file_id={$fileId})");
            }

            $processed++;
        }

        $this->newLine();
        $this->info("Selesai. Diproses: {$processed}, dilangkau (DB sedia ada): {$skipped}, gagal: {$fail}");

        return $fail > 0 ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveOpenAiFileId(array $row): ?string
    {
        if (! empty($row['id']) && is_string($row['id'])) {
            return $row['id'];
        }
        if (! empty($row['file_id']) && is_string($row['file_id'])) {
            return $row['file_id'];
        }

        return null;
    }

    private function uniquePublicKnowledgeFilename(string $filename): string
    {
        if (! Storage::disk('public')->exists('knowledge/'.$filename)) {
            return $filename;
        }
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $ext !== '' ? '.'.$ext : '';
        for ($i = 1; $i < 1000; $i++) {
            $candidate = $base.'-'.$i.$suffix;
            if (! Storage::disk('public')->exists('knowledge/'.$candidate)) {
                return $candidate;
            }
        }

        return $base.'-'.uniqid('', true).$suffix;
    }
}
