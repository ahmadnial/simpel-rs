<?php

declare(strict_types=1);

// Run as the deployment user. Never load or store an administrative token here.
$configPath = $argv[1] ?? '/var/www/apps/simpel-rs/bootstrap/cache/config.php';
$checkOnly = ($argv[2] ?? '') === '--check';

try {
    $config = require $configPath;
    $bao = $config['tte']['providers']['openbao'] ?? [];
    $token = (string) ($bao['token'] ?? '');
    $address = rtrim((string) ($bao['address'] ?? ''), '/');
    if ($address === '' || strlen($token) < 16) {
        throw new RuntimeException('OpenBao configuration unavailable');
    }
    $handle = curl_init($address.'/v1/auth/token/'.($checkOnly ? 'lookup-self' : 'renew-self'));
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['X-Vault-Token: '.$token, 'Content-Type: application/json'],
    ]);
    if (! $checkOnly) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, '{}');
    }
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $networkError = curl_errno($handle);
    curl_close($handle);
    unset($token);
    if ($networkError !== 0 || $status !== 200) {
        throw new RuntimeException("OpenBao request failed: HTTP {$status}, curl {$networkError}");
    }
    $response = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
    $metadata = $response[$checkOnly ? 'data' : 'auth'] ?? [];
    $ttl = (int) ($metadata[$checkOnly ? 'ttl' : 'lease_duration'] ?? 0);
    if (($metadata['renewable'] ?? false) !== true || $ttl < 86400) {
        throw new RuntimeException('OpenBao token is not renewable or TTL is below 24 hours');
    }
    // Never print API bodies: renewal responses contain the application token.
    echo json_encode(['operation' => $checkOnly ? 'check' : 'renew', 'ok' => true, 'ttl_seconds' => $ttl], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    // Exception text from parsing/configuration may contain sensitive content.
    fwrite(STDERR, "OpenBao token maintenance failed; inspect configuration, connectivity and token validity on the VM.\n");
    exit(1);
}
