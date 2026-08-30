<?php

namespace Tests\Unit;

use App\Services\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Root23\JsonCanonicalizer\JsonCanonicalizer;

class CanonicalJsonTest extends TestCase
{
    public function test_rfc_8785_known_answer_vector_matches_exact_bytes(): void
    {
        $input = [
            'numbers' => [333333333.33333329, 1E30, 4.50, 2e-3, 0.000000000000000000000000001],
            'string' => "€$\u{000F}\nA'B\"\\\"/",
            'literals' => [null, true, false],
        ];
        $expected = '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\\u000f\\nA\'B\\"\\\\\\"/"}';

        $this->assertSame($expected, (new JsonCanonicalizer)->canonicalize($input));
    }

    public function test_application_serializer_is_order_independent_and_rejects_floats(): void
    {
        $canonical = new CanonicalJson;

        $this->assertSame(
            '{"a":{"x":1,"y":2},"b":2,"unicode":"€"}',
            $canonical->encode(['unicode' => '€', 'b' => 2, 'a' => ['y' => 2, 'x' => 1]])
        );
        $this->assertSame(
            $canonical->encode(['b' => 2, 'a' => 1]),
            $canonical->encode(['a' => 1, 'b' => 2])
        );

        $this->expectException(InvalidArgumentException::class);
        $canonical->encode(['ambiguous' => 1.0]);
    }
}
