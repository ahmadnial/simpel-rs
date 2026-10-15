<?php

return [
    // One renderer per deployment; never silently switch engines after a failure.
    'renderer' => env('DOCUMENT_RENDERER', env('GOTENBERG_URL') ? 'gotenberg' : 'libreoffice'),
    'gotenberg_url' => env('GOTENBERG_URL', 'http://localhost:3000'),
    'libreoffice_path' => env('LIBREOFFICE_PATH'),
    'conversion_timeout' => 60,
    // Increment when the renderer version or installed fonts change.
    'render_revision' => env('DOCUMENT_RENDER_REVISION', '1'),
];
