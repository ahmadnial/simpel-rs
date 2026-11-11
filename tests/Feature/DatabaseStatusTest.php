<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DatabaseStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_inspect_database_status(): void
    {
        $this->getJson(route('database.status'))->assertUnauthorized();
    }

    public function test_authenticated_status_checks_actual_sqlite_connection_without_exposing_paths(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('database.status'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['database' => ':memory:', 'connected' => true, 'environment' => 'unknown']);
    }

    public function test_sql_server_name_comes_from_write_connection_instead_of_configured_name(): void
    {
        $user = User::factory()->create();
        $manager = DB::getFacadeRoot();
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('getDatabaseName')->never();
        $connection->shouldReceive('selectOne')->once()->with('SELECT DB_NAME() AS name', [], false)
            ->andReturn((object) ['name' => 'sivera']);
        try {
            DB::shouldReceive('connection')->once()->andReturn($connection);
            $this->actingAs($user)->getJson(route('database.status'))
                ->assertOk()->assertExactJson(['database' => 'sivera', 'connected' => true, 'environment' => 'production']);
        } finally {
            DB::swap($manager);
        }
    }

    public function test_connection_error_does_not_expose_driver_details_or_claim_connection(): void
    {
        $user = User::factory()->create();
        $manager = DB::getFacadeRoot();
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('Private driver credentials and host details'));
        try {
            DB::shouldReceive('connection')->once()->andReturn($connection);
            $this->actingAs($user)->getJson(route('database.status'))
                ->assertOk()->assertExactJson(['database' => null, 'connected' => false, 'environment' => 'unknown']);
        } finally {
            DB::swap($manager);
        }
    }
}
