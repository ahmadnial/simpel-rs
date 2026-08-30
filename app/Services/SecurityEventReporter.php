<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class SecurityEventReporter
{
    /** @param array<string,mixed> $context */
    public function report(string $event, array $context = [], string $severity = 'info'): void
    {
        try {
            $payload = $this->sanitize($context) + ['security_event' => $event, 'schema_version' => '1.0'];
            $logger = Log::channel((string) config('tte.monitoring.channel', 'stack'));
            in_array($severity, ['warning', 'error', 'critical'], true)
                ? $logger->{$severity}('tte_security_event', $payload)
                : $logger->info('tte_security_event', $payload);
        } catch (Throwable) {
            // Kegagalan sink monitoring tidak boleh membocorkan payload atau mengubah hasil transaksi.
        }
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function sanitize(array $context): array
    {
        $sensitive = '/otp|password|secret|token|verifier|authorization|cookie|session|private.?key/i';
        $clean = [];
        foreach ($context as $key => $value) {
            if (preg_match($sensitive, (string) $key)) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = get_debug_type($value);
            }
        }

        return $clean;
    }
}
