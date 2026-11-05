<?php

namespace Tests\Unit;

use App\Services\BaseCompartida\Candidata;
use App\Services\BaseCompartida\Eleccion;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/** La regla de supervivencia entre bases con el mismo cluster: mas eventos, a igualdad menor identificador, con histeresis. */
class EleccionBaseTest extends TestCase
{
    private function base(string $id, int $eventos, ?string $ultimo = null): Candidata
    {
        return new Candidata("10.0.0.{$id}:5432", $id, $eventos, $ultimo === null ? null : CarbonImmutable::parse($ultimo));
    }

    public function test_sin_candidatas_no_hay_eleccion(): void
    {
        $this->assertNull((new Eleccion)->elegir([]));
    }

    public function test_gana_la_base_con_mas_eventos(): void
    {
        $elegida = (new Eleccion)->elegir([$this->base('1', 10), $this->base('2', 500), $this->base('3', 40)]);

        $this->assertSame('2', $elegida->identificador);
    }

    public function test_a_igualdad_de_eventos_gana_el_menor_identificador(): void
    {
        $elegida = (new Eleccion)->elegir([$this->base('30', 7), $this->base('4', 7), $this->base('9', 7)]);

        $this->assertSame('30', $elegida->identificador, 'se compara como texto, igual en todos los dispositivos');
    }

    public function test_la_eleccion_no_depende_del_orden_de_entrada(): void
    {
        $bases = [$this->base('5', 80), $this->base('2', 80), $this->base('9', 120), $this->base('1', 3)];
        $eleccion = new Eleccion;

        foreach ([$bases, array_reverse($bases), [$bases[2], $bases[0], $bases[3], $bases[1]]] as $orden) {
            $this->assertSame('9', $eleccion->elegir($orden)->identificador);
        }
    }

    public function test_quien_ya_escribe_en_una_base_la_mantiene_dentro_del_margen(): void
    {
        $actual = $this->base('1', 100);
        $eleccion = new Eleccion(0.05);

        $this->assertSame('1', $eleccion->elegir([$actual, $this->base('2', 105)], $actual)->identificador, '105 no supera 100 + 5 %');
        $this->assertSame('2', $eleccion->elegir([$actual, $this->base('2', 106)], $actual)->identificador, '106 si la supera');
    }

    public function test_sin_base_actual_no_hay_histeresis(): void
    {
        $this->assertSame('2', (new Eleccion(0.05))->elegir([$this->base('1', 100), $this->base('2', 101)])->identificador);
    }

    public function test_una_base_actual_vacia_cede_ante_cualquiera_con_datos(): void
    {
        $actual = $this->base('1', 0);

        $this->assertSame('2', (new Eleccion(0.05))->elegir([$actual, $this->base('2', 1)], $actual)->identificador);
    }

    public function test_la_base_actual_que_ya_no_esta_entre_las_candidatas_no_se_conserva(): void
    {
        $desaparecida = $this->base('1', 1000);

        $this->assertSame('2', (new Eleccion)->elegir([$this->base('2', 5), $this->base('3', 4)], $desaparecida)->identificador);
    }

    public function test_el_margen_cero_elige_siempre_la_mejor(): void
    {
        $actual = $this->base('1', 100);

        $this->assertSame('2', (new Eleccion(0.0))->elegir([$actual, $this->base('2', 101)], $actual)->identificador);
    }

    public function test_un_margen_negativo_es_un_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Eleccion(-0.1);
    }

    public function test_sobran_las_bases_que_no_son_la_elegida_y_estan_en_silencio(): void
    {
        $ahora = CarbonImmutable::parse('2026-11-05 12:00:00');
        $elegida = $this->base('1', 500, '2026-11-05 11:59:00');
        $callada = $this->base('2', 40, '2026-11-05 11:30:00');
        $activa = $this->base('3', 60, '2026-11-05 11:55:00');
        $vacia = $this->base('4', 0);

        $sobran = (new Eleccion)->sobrantes([$elegida, $callada, $activa, $vacia], $elegida, $ahora, 10);

        $this->assertSame(['2', '4'], array_map(fn (Candidata $c): string => $c->identificador, $sobran));
    }

    public function test_la_elegida_nunca_sobra_aunque_este_en_silencio(): void
    {
        $ahora = CarbonImmutable::parse('2026-11-05 12:00:00');
        $elegida = $this->base('1', 500, '2026-11-04 08:00:00');

        $this->assertSame([], (new Eleccion)->sobrantes([$elegida], $elegida, $ahora, 10));
    }

    public function test_el_silencio_empieza_justo_al_cumplirse_el_plazo(): void
    {
        $ahora = CarbonImmutable::parse('2026-11-05 12:00:00');

        $this->assertTrue(Eleccion::enSilencio($this->base('1', 1, '2026-11-05 11:50:00'), $ahora, 10));
        $this->assertFalse(Eleccion::enSilencio($this->base('1', 1, '2026-11-05 11:50:01'), $ahora, 10));
    }
}
