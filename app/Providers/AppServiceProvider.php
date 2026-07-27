<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\DatabaseConnected;
use Illuminate\Database\Events\StatementPrepared;

use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.custom');

        Event::listen(DatabaseConnected::class, function (DatabaseConnected $event) {
            if ($event->connection->getDriverName() === 'sqlsrv') {
                $this->setSqlSrvAnsiOptions($event->connection->getPdo());
            }
        });

        Event::listen(StatementPrepared::class, function (StatementPrepared $event) {
            if ($event->connection->getDriverName() === 'sqlsrv') {
                $this->setSqlSrvAnsiOptions($event->connection->getPdo());
            }
        });
    }

    private function setSqlSrvAnsiOptions($pdo): void
    {
        static $configured = [];

        if (!$pdo) {
            return;
        }

        $splId = spl_object_id($pdo);
        if (isset($configured[$splId])) {
            return;
        }
        $configured[$splId] = true;

        try {
            $pdo->exec("
                SET ANSI_NULLS ON;
                SET ANSI_PADDING ON;
                SET ANSI_WARNINGS ON;
                SET CONCAT_NULL_YIELDS_NULL ON;
                SET QUOTED_IDENTIFIER ON;
                SET NUMERIC_ROUNDABORT OFF;
                SET DATEFORMAT ymd;
            ");
        } catch (\Throwable $e) {
            // Ignore if execution fails
        }
    }
}
