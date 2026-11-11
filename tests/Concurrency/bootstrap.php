<?php

namespace Tests\Concurrency;

// Standalone opt-in staging harness. Never run the PHPUnit RefreshDatabase suite here.
use App\Services\DocumentPdfService;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeDocumentPdfService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function bootWorkflowQc(string $directory): void
{
    $root = dirname(__DIR__, 2);
    $credentials = Dotenv::createArrayBacked($root)->load();
    foreach ([
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
        'DB_CONNECTION' => 'sqlsrv', 'DB_DATABASE' => 'simpel-dev', 'DB_URL' => '',
        'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'single',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = config('database.connections.sqlsrv');
    $connection['database'] = 'simpel-dev';
    $connection['url'] = null;
    foreach (['host', 'port', 'username', 'password'] as $field) {
        $connection[$field] = $credentials['DB_'.strtoupper($field)] ?? $connection[$field];
    }
    config([
        'database.default' => 'sqlsrv', 'database.connections.sqlsrv' => $connection,
        'filesystems.disks.local.root' => $directory.'/storage',
        'logging.channels.single.path' => $directory.'/laravel.log',
        'app.url' => 'http://workflow-qc.invalid',
    ]);
    DB::purge('sqlsrv');
    if (DB::connection()->getDatabaseName() !== 'simpel-dev' || DB::selectOne('SELECT DB_NAME() AS db')->db !== 'simpel-dev') {
        throw new RuntimeException('QC requires the exact database simpel-dev.');
    }
    DB::connection()->beforeExecuting(function ($sql): void {
        if (preg_match('/\bsivera\b|\bUSE\s+/i', $sql)) {
            throw new RuntimeException('Database switching/production access is forbidden in QC.');
        }
    });
    DB::statement('SET LOCK_TIMEOUT 45000');
    DB::statement('SET DEADLOCK_PRIORITY LOW');
    app()->instance(DocumentPdfService::class, new FakeDocumentPdfService);
}
