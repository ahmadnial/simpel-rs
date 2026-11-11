<?php

use App\Models\Document;
use App\Services\DocxTemplateProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

// Two independent PHP processes exercise publication of one shared DOCX cache.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $source, $root, $worker] = $argv;
config(['filesystems.disks.local.root' => $root]);
Storage::forgetDisk('local');
$document = new Document(['judul' => 'QC concurrent cache']);
$document->created_at = '2026-11-11 10:00:00';
$document->setRelation('signature', null)->setRelation('pengusul', null)->setRelation('unit', null);
file_put_contents($root.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($root.'/go')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(1000);
}
$path = app(DocxTemplateProcessor::class)->process($source, $document);
$hash = hash_file('sha256', $path);
usleep(200000);
echo json_encode(['first' => $hash, 'later' => hash_file('sha256', $path)], JSON_THROW_ON_ERROR);
