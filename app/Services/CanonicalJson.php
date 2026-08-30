<?php

namespace App\Services;

use InvalidArgumentException;
use Root23\JsonCanonicalizer\JsonCanonicalizer;

class CanonicalJson
{
    public function __construct(private readonly JsonCanonicalizer $canonicalizer = new JsonCanonicalizer) {}

    public function encode(mixed $value): string
    {
        $this->rejectFloats($value);

        return $this->canonicalizer->canonicalize($value);
    }

    private function rejectFloats(mixed $value): void
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Float tidak diizinkan pada manifest bukti.');
        }
        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $child) {
                $this->rejectFloats($child);
            }
        }
    }
}
