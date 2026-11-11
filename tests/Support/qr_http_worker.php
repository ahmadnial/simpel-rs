<?php

use App\Services\DocumentQrOverlay;
use Illuminate\Contracts\Console\Kernel;

// Isolated HTTP regression worker: fixture paths only, no database access.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') {
    echo 'ready';

    return;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
if (getenv('SIMPLE_QR_HTTP_OLD_CONFIG')) {
    $settings = config('documents');
    unset($settings['pdf_python'], $settings['pdftotext_path'], $settings['pdftoppm_path']);
    config(['documents' => $settings]);
}
header('Content-Type: application/json');
try {
    $target = getenv('SIMPLE_QR_HTTP_OUTPUT');
    copy(getenv('SIMPLE_QR_HTTP_SOURCE_PDF'), $target);
    app(DocumentQrOverlay::class)->apply(getenv('SIMPLE_QR_HTTP_DOCX'), $target);
    echo json_encode(['ok' => true, 'sapi' => PHP_SAPI, 'parent_has_path' => getenv('PATH') !== false, 'pdf_header' => file_get_contents($target, false, null, 0, 5)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'exception_type' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
