<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = null;
        try {
            $connection = DB::connection();
            // Check the write connection used by mutations, not a read replica.
            $database = match ($connection->getDriverName()) {
                'sqlsrv' => $connection->selectOne('SELECT DB_NAME() AS name', [], false)->name,
                'mysql', 'mariadb' => $connection->selectOne('SELECT DATABASE() AS name', [], false)->name,
                'pgsql' => $connection->selectOne('SELECT current_database() AS name', [], false)->name,
                'sqlite' => basename(collect($connection->select('PRAGMA database_list', [], false))->firstWhere('name', 'main')->file ?: ':memory:'),
                default => null,
            };
        } catch (Throwable) {
            // Never expose driver errors, host names, credentials, or file paths.
        }

        return response()->json([
            'database' => $database,
            'connected' => is_string($database) && $database !== '',
            'environment' => match (strtolower($database ?? '')) {
                'sivera' => 'production',
                'simpel-dev' => 'staging',
                default => 'unknown',
            },
        ])->header('Cache-Control', 'private, no-store');
    }
}
