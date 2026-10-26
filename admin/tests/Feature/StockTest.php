<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\Compra;
use App\Models\Existencia;
use App\Models\Hogar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private function linea(Compra $compra, Alimento $alimento, float $cantidad, string $unidad = 'g', ?string $caduca = null): void
    {
        $linea = $compra->lineas()->make(['cantidad' => $cantidad, 'unidad' => $unidad, 'caduca_el' => $caduca]);
        $linea->alimento_id = $alimento->id;
        $linea->save();
    }

    public function test_registrar_una_compra_crea_la_existencia(): void
    {
        $hogar = Hogar::factory()->create();
        $arroz = Alimento::factory()->create();

        $this->linea(Compra::factory()->for($hogar)->create(), $arroz, 1000);

        $existencia = Existencia::query()->where('hogar_id', $hogar->id)->sole();
        $this->assertSame('1000.00', $existencia->cantidad);
        $this->assertSame($arroz->id, $existencia->alimento_id);
    }

    public function test_una_segunda_compra_suma_y_adelanta_la_caducidad(): void
    {
        $hogar = Hogar::factory()->create();
        $yogur = Alimento::factory()->create();

        $this->linea(Compra::factory()->for($hogar)->create(), $yogur, 4, 'ud', '2026-11-20');
        $this->linea(Compra::factory()->for($hogar)->create(), $yogur, 6, 'ud', '2026-11-10');

        $existencia = Existencia::query()->where('hogar_id', $hogar->id)->sole();
        $this->assertSame('10.00', $existencia->cantidad);
        $this->assertTrue($existencia->caduca_el->isSameDay(CarbonImmutable::parse('2026-11-10')));
    }

    public function test_otra_unidad_o_otro_hogar_no_se_mezclan(): void
    {
        $hogar = Hogar::factory()->create();
        $otro = Hogar::factory()->create();
        $leche = Alimento::factory()->create();

        $this->linea(Compra::factory()->for($hogar)->create(), $leche, 1, 'l');
        $this->linea(Compra::factory()->for($hogar)->create(), $leche, 500, 'ml');
        $this->linea(Compra::factory()->for($otro)->create(), $leche, 1, 'l');

        $this->assertSame(2, Existencia::query()->where('hogar_id', $hogar->id)->count());
        $this->assertSame(1, Existencia::query()->where('hogar_id', $otro->id)->count());
    }
}
