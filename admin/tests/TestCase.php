<?php

namespace Tests;

use App\Services\Instancia;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Una instancia fija para la auditoria: sin ella se generaria (y guardaria en storage/app) una distinta en cada equipo.
        config(['nutrition.instancia' => 'prueba']);
        Instancia::olvidar();

        // El chat vive en un SQLite aparte (conexion «chat»): en memoria y recien migrado en cada prueba.
        config(['database.connections.chat.database' => ':memory:']);
        app('db')->purge('chat');
        Artisan::call('migrate', ['--database' => 'chat', '--path' => 'database/migrations/chat', '--force' => true]);
    }
}
