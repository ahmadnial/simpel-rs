<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentVerification;
use App\Models\Delegation;
use Tests\TestCase;

class SqlServerForeignKeyCastTest extends TestCase
{
    public function test_numeric_foreign_keys_are_normalized_before_strict_authorization_checks(): void
    {
        // sqlsrv returns numeric/unsignedBigInteger columns as strings. These are
        // representative raw values from a hydrated SQL Server model, not values
        // assigned through Eloquent's normal setter.
        $verification = new DocumentVerification();
        $verification->setRawAttributes([
            'document_id' => '10031', 'document_version_id' => '10080',
            'workflow_step_id' => '10017', 'verifikator_id' => '10060',
        ]);

        $document = new Document();
        $document->setRawAttributes(['pengusul_id' => '10060', 'unit_id' => '12']);

        $signature = new DocumentSignature();
        $signature->setRawAttributes(['penandatangan_id' => '10060']);

        $delegation = new Delegation();
        $delegation->setRawAttributes(['pejabat_id' => '10060', 'delegasi_id' => '10061']);

        $userId = 10060;
        $this->assertSame($userId, $verification->verifikator_id);
        $this->assertSame($userId, $document->pengusul_id);
        $this->assertSame($userId, $signature->penandatangan_id);
        $this->assertSame($userId, $delegation->pejabat_id);
        $this->assertSame(10061, $delegation->delegasi_id);
    }
}
