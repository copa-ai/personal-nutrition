<?php

namespace Tests;

use App\Services\Instancia;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Una instancia fija para la auditoria: sin ella se generaria (y guardaria en storage/app) una distinta en cada equipo.
        config(['nutrition.instancia' => 'prueba']);
        Instancia::olvidar();
    }
}
