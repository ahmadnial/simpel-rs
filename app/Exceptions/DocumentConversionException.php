<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentConversionException extends HttpException
{
    public function __construct(string $message = 'Layanan konversi belum dapat membuat PDF. Berkas tetap tersimpan; coba lagi atau hubungi administrator.', ?\Throwable $previous = null, int $statusCode = 503)
    {
        parent::__construct($statusCode, $message, $previous);
    }
}
