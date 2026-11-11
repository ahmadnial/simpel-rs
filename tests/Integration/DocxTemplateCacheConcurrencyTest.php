<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\WordFixtures;
use Tests\TestCase;

class DocxTemplateCacheConcurrencyTest extends TestCase
{
    use WordFixtures;

    public function test_two_workers_publish_one_immutable_docx_with_consistent_markers(): void
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('concurrent-cache');
        File::ensureDirectoryExists($root);
        $file = $this->wordUpload(str_repeat('<w:p><w:r><w:t>Paragraf uji</w:t></w:r></w:p>', 1500).'<w:p><w:r><w:t>${qr_code} ${tanggal_ttd}</w:t></w:r></w:p>');
        $originalHash = hash_file('sha256', $file->getRealPath());
        $workers = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/docx_cache_worker.php'), $file->getRealPath(), $root, (string) $worker], base_path(), [
                    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => base_path('bootstrap/cache/testing-config.php'),
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                ]);
                $process->setTimeout(15)->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($root.'/ready-1') || ! is_file($root.'/ready-2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Both cache workers must reach the shared barrier.');
                }
                usleep(1000);
            }
            File::put($root.'/go', 'go');
            $results = [];
            foreach ($workers as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $this->assertJson($process->getOutput(), json_encode($process->getOutput()));
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame($results[0], $results[1]);
            $this->assertSame($results[0]['first'], $results[0]['later']);
            $this->assertCount(1, File::files($root.'/temp_processed'));
            $this->assertSame($originalHash, hash_file('sha256', $file->getRealPath()));
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
