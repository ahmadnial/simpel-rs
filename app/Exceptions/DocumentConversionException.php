<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentConversionException extends HttpException
{
    public function __construct(string $message = 'Layanan konversi belum dapat membuat PDF. Berkas tetap tersimpan; coba lagi atau hubungi administrator.', ?\Throwable $previous = null)
    {
        parent::__construct(503, $message, $previous);
    }
}
