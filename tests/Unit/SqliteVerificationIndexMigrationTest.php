<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteVerificationIndexMigrationTest extends TestCase
{
    public function test_sqlite_index_repair_does_not_execute_ddl_on_sql_server(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        DB::shouldReceive('statement')->never();

        $migration = require database_path('migrations/2026_11_11_000001_restore_sqlite_active_verification_index.php');
        $migration->up();
        $migration->down();
    }
}
