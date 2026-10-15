<?php

// Synthetic long PDF for browser virtualization tests; no application/database bootstrap.
require __DIR__.'/../../vendor/autoload.php';

$pdf = new Dompdf\Dompdf;
$html = '<html><head><style>body{font-family:DejaVu Sans}.page{page-break-before:always}</style></head><body>';
for ($page = 1; $page <= 92; $page++) {
    $html .= '<div'.($page > 1 ? ' class="page"' : '').'><h1>Halaman '.$page.'</h1><p>Fixture pengujian scroll dokumen.</p></div>';
}
$pdf->loadHtml($html.'</body></html>');
$pdf->setPaper('A4');
$pdf->render();
file_put_contents($argv[1], $pdf->output());
