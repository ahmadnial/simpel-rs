<?php

namespace Tests\Unit;

use App\Services\SecurityEventReporter;
use Tests\TestCase;

class SecurityEventReporterTest extends TestCase
{
    public function test_sensitive_structured_fields_are_recursively_redacted(): void
    {
        $clean = app(SecurityEventReporter::class)->sanitize([
            'actor_id' => 7,
            'otp' => '12345678',
            'nested' => ['password' => 'secret', 'result' => 'locked'],
            'session_id_hash' => 'also-sensitive',
        ]);

        $this->assertSame(7, $clean['actor_id']);
        $this->assertSame('[REDACTED]', $clean['otp']);
        $this->assertSame('[REDACTED]', $clean['nested']['password']);
        $this->assertSame('locked', $clean['nested']['result']);
        $this->assertSame('[REDACTED]', $clean['session_id_hash']);
    }
}
