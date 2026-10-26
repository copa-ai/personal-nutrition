<?php

namespace Tests\Feature;

use App\Services\Claude\Tarifas;
use Carbon\Carbon;
use Tests\TestCase;

class TarifasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['agente.precios' => [
            'claude-haiku-4-5' => ['entrada' => 1.0, 'salida' => 5.0, 'cache_lectura' => 0.1, 'cache_escritura' => 1.25, 'busqueda_web' => 0.01],
            'claude-haiku' => ['entrada' => 9.0, 'salida' => 9.0],
            'claude-sonnet-5-5' => ['entrada' => 2.0, 'salida' => 10.0, 'cache_lectura' => 0.2, 'cache_escritura' => 2.5, 'busqueda_web' => 0.01],
        ]]);
    }

    public function test_coincidencia_exacta(): void
    {
        $this->assertSame('claude-sonnet-5-5', Tarifas::para('claude-sonnet-5-5')['modelo_tarifa']);
    }

    public function test_un_modelo_con_sufijo_usa_el_prefijo_mas_largo(): void
    {
        $t = Tarifas::para('claude-haiku-4-5-20251001');

        $this->assertSame('claude-haiku-4-5', $t['modelo_tarifa']);
        $this->assertSame(1.0, $t['entrada']);
    }

    public function test_un_modelo_sin_tarifa_o_vacio_da_null_y_nunca_cero(): void
    {
        $this->assertNull(Tarifas::para('claude-desconocido-9'));
        $this->assertNull(Tarifas::para(''));
        $this->assertNull(Tarifas::para(null));
    }

    public function test_formula_de_coste(): void
    {
        $c = Tarifas::coste([
            'input_tokens' => 1_000_000, 'output_tokens' => 500_000, 'cache_read' => 2_000_000, 'cache_creation' => 400_000, 'web_search' => 3,
        ], Tarifas::para('claude-sonnet-5-5'));

        $this->assertEqualsWithDelta(2.0, $c['coste_entrada'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $c['coste_salida'], 1e-9);
        $this->assertEqualsWithDelta(0.4 + 1.0, $c['coste_cache'], 1e-9);
        $this->assertEqualsWithDelta(0.03, $c['coste_web'], 1e-9);
        $this->assertEqualsWithDelta(2.0 + 5.0 + 1.4 + 0.03, $c['coste_total'], 1e-9);
    }

    public function test_la_instantanea_guarda_solo_la_tarifa_y_la_fecha(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');

        $i = Tarifas::instantanea(['modelo_tarifa' => 'm', 'entrada' => 1, 'salida' => 2, 'otra_cosa' => 'x']);

        $this->assertSame(['modelo_tarifa' => 'm', 'entrada' => 1, 'salida' => 2, 'fecha' => '2026-11-01'], $i);
    }
}
