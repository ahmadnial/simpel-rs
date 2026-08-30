<?php

namespace Tests\Feature;

use Tests\TestCase;

class CredentialLeakGuardTest extends TestCase
{
    public function test_production_seeders_do_not_contain_literal_passwords(): void
    {
        $files = glob(database_path('seeders/*.php')) ?: [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                "/['\"]password['\"]\\s*=>\\s*['\"][^'\"]+['\"]/",
                $contents,
                basename($file).' memuat password literal.',
            );
        }
    }

    public function test_tte_secret_placeholders_are_blank_and_distinct(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^TTE_OTP_HMAC_SECRET=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^TTE_OTP_DESTINATION_HMAC_SECRET=\s*$/m', $example);
        $this->assertStringNotContainsString('debug_otp', file_get_contents(app_path('Http/Controllers/TandaTanganController.php')));
    }
}
