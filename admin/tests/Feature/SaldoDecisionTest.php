<?php

namespace Tests\Feature;

use App\Services\Coder\Saldo;
use Tests\TestCase;

/** The pure rule of App\Services\Coder\Saldo: no database, no node. */
class SaldoDecisionTest extends TestCase
{
    /** Samples with a constant burn of $tasa MU per second, the last one $fin MU. Five samples, 300 s apart. */
    private function muestras(float $tasa = 1.0, int $n = 5, int $fin = 1_000_000, int $dep = 0): array
    {
        $m = [];
        for ($i = 0; $i < $n; $i++) {
            $edad = ($n - 1 - $i) * 300;
            $m[] = ['t' => 1_000_000 + $i * 300, 'saldo' => (int) ($fin + $edad * $tasa), 'dep' => $dep];
        }

        return $m;
    }

    /** A request that passes all controls: room is 99 000 (cap), 199 200 (percentage) and 827 200 (life). */
    private function base(array $cambios = []): array
    {
        return array_replace([
            'monto' => 1000,
            'saldo_coder' => 1000,
            'saldo_nutrition' => 1_000_000,
            'enviado' => 0,
            'tope' => 100_000,
            'porcentaje' => 20,
            'horas_minimas' => 48,
            'muestras' => $this->muestras(),
        ], $cambios);
    }

    public function test_una_cantidad_pequena_pasa_los_tres_controles(): void
    {
        $d = Saldo::decidir($this->base());

        $this->assertTrue($d['permitido']);
        $this->assertNull($d['motivo']);
        $this->assertNull($d['control']);
        $this->assertSame(99_000, $d['maximo_mu']);
        $this->assertEqualsWithDelta(1.0, $d['tasa_mu_s'], 0.0001);
    }

    public function test_el_tope_en_el_limite_pasa_y_un_mu_mas_no(): void
    {
        $this->assertTrue(Saldo::decidir($this->base(['monto' => 99_000]))['permitido']);

        $d = Saldo::decidir($this->base(['monto' => 99_001]));
        $this->assertFalse($d['permitido']);
        $this->assertSame(Saldo::CTRL_TOPE, $d['control']);
        $this->assertStringContainsString('99.000', $d['motivo']);
    }

    public function test_lo_enviado_cuenta_para_el_tope(): void
    {
        $d = Saldo::decidir($this->base(['enviado' => 50_000, 'monto' => 49_001]));

        $this->assertSame(Saldo::CTRL_TOPE, $d['control']);
        $this->assertSame(49_000, $d['maximo_mu']);
        $this->assertTrue(Saldo::decidir($this->base(['enviado' => 50_000, 'monto' => 49_000]))['permitido']);
    }

    public function test_enviado_como_cadena_o_nulo_se_lee_bien(): void
    {
        $this->assertSame(49_000, Saldo::decidir($this->base(['enviado' => '50000']))['maximo_mu']);
        $this->assertSame(99_000, Saldo::decidir($this->base(['enviado' => null]))['maximo_mu']);
    }

    public function test_el_saldo_actual_del_coder_cuenta_para_el_tope(): void
    {
        $d = Saldo::decidir($this->base(['saldo_coder' => 90_000, 'monto' => 10_001]));

        $this->assertSame(Saldo::CTRL_TOPE, $d['control']);
        $this->assertSame(10_000, $d['maximo_mu']);
    }

    public function test_un_tope_ya_superado_no_deja_enviar_nada(): void
    {
        $d = Saldo::decidir($this->base(['saldo_coder' => 200_000, 'monto' => 1]));

        $this->assertSame(Saldo::CTRL_TOPE, $d['control']);
        $this->assertSame(0, $d['maximo_mu']);
    }

    public function test_sin_tope_no_se_envia_nada(): void
    {
        $d = Saldo::decidir($this->base(['tope' => null]));

        $this->assertFalse($d['permitido']);
        $this->assertSame(Saldo::CTRL_TOPE, $d['control']);
        $this->assertSame(0, $d['maximo_mu']);
    }

    public function test_el_porcentaje_en_el_limite_pasa_y_un_mu_mas_no(): void
    {
        // Joint balance 1 001 000, 20 % is 200 200, the Coder holds 1 000: room 199 200.
        $p = ['tope' => 10 ** 12];
        $this->assertTrue(Saldo::decidir($this->base($p + ['monto' => 199_200]))['permitido']);

        $d = Saldo::decidir($this->base($p + ['monto' => 199_201]));
        $this->assertFalse($d['permitido']);
        $this->assertSame(Saldo::CTRL_PORCENTAJE, $d['control']);
        $this->assertSame(199_200, $d['maximo_mu']);
        $this->assertStringContainsString('20 %', $d['motivo']);
    }

    public function test_el_porcentaje_cero_no_deja_enviar_y_el_cien_no_limita(): void
    {
        $this->assertSame(Saldo::CTRL_PORCENTAJE, Saldo::decidir($this->base(['porcentaje' => 0, 'tope' => 10 ** 12]))['control']);
        $d = Saldo::decidir($this->base(['porcentaje' => 100, 'tope' => 10 ** 12, 'monto' => 800_000]));
        $this->assertTrue($d['permitido']);
    }

    public function test_un_porcentaje_fuera_de_rango_se_recorta(): void
    {
        $d = Saldo::decidir($this->base(['porcentaje' => 500, 'tope' => 10 ** 12]));
        $this->assertSame(827_200, $d['maximo_mu']);   // 100 %: only the life limits

        $d = Saldo::decidir($this->base(['porcentaje' => -5, 'tope' => 10 ** 12]));
        $this->assertSame(0, $d['maximo_mu']);
    }

    public function test_la_vida_minima_en_el_limite_pasa_y_un_mu_mas_no(): void
    {
        // Burn 1 MU/s and 48 h need 172 800 MU. Nutrition has 200 000: room 27 200.
        $p = ['saldo_nutrition' => 200_000, 'porcentaje' => 100, 'tope' => 10 ** 12, 'muestras' => $this->muestras(1.0, 5, 200_000)];
        $this->assertTrue(Saldo::decidir($this->base($p + ['monto' => 27_200]))['permitido']);

        $d = Saldo::decidir($this->base($p + ['monto' => 27_201]));
        $this->assertFalse($d['permitido']);
        $this->assertSame(Saldo::CTRL_VIDA, $d['control']);
        $this->assertSame(27_200, $d['maximo_mu']);
    }

    public function test_con_la_vida_ya_bajo_el_minimo_no_se_envia_nada(): void
    {
        $p = ['saldo_nutrition' => 172_799, 'porcentaje' => 100, 'tope' => 10 ** 12, 'muestras' => $this->muestras(1.0, 5, 172_799)];
        $d = Saldo::decidir($this->base($p + ['monto' => 1]));

        $this->assertSame(Saldo::CTRL_VIDA, $d['control']);
        $this->assertSame(0, $d['maximo_mu']);
        $this->assertStringContainsString('mínimo es 48,0 h', $d['motivo']);
    }

    public function test_con_la_vida_justo_en_el_minimo_se_puede_pero_no_se_puede_gastar_mas(): void
    {
        $p = ['saldo_nutrition' => 172_800, 'porcentaje' => 100, 'tope' => 10 ** 12, 'muestras' => $this->muestras(1.0, 5, 172_800)];
        $d = Saldo::decidir($this->base($p + ['monto' => 1]));

        $this->assertSame(Saldo::CTRL_VIDA, $d['control']);   // the transfer would go under the minimum
        $this->assertSame(0, $d['maximo_mu']);
        $this->assertEqualsWithDelta(48.0, $d['vida_horas'], 0.001);
    }

    public function test_sin_minimo_de_horas_solo_limitan_los_otros_controles(): void
    {
        $d = Saldo::decidir($this->base(['horas_minimas' => 0, 'monto' => 99_000]));

        $this->assertTrue($d['permitido']);
    }

    public function test_vida_desconocida_no_envia_nada_aunque_los_otros_controles_pasen(): void
    {
        $d = Saldo::decidir($this->base(['muestras' => []]));

        $this->assertFalse($d['permitido']);
        $this->assertSame(Saldo::CTRL_VIDA, $d['control']);
        $this->assertSame(0, $d['maximo_mu']);
        $this->assertNull($d['vida_horas']);
        $this->assertStringContainsString('faltan muestras', $d['motivo']);
    }

    public function test_el_orden_es_tope_porcentaje_vida(): void
    {
        // All three fail: the cap is reported.
        $todo = $this->base(['monto' => 10 ** 9, 'muestras' => []]);
        $this->assertSame(Saldo::CTRL_TOPE, Saldo::decidir($todo)['control']);

        // The cap passes, the percentage and the life fail: the percentage is reported.
        $this->assertSame(Saldo::CTRL_PORCENTAJE, Saldo::decidir([...$todo, 'tope' => 10 ** 15, 'monto' => 300_000])['control']);

        // The cap and the percentage pass, the life is unknown: the life is reported.
        $this->assertSame(Saldo::CTRL_VIDA, Saldo::decidir([...$todo, 'monto' => 5000])['control']);
    }

    public function test_el_maximo_es_el_menor_de_los_tres_margenes(): void
    {
        $this->assertSame(99_000, Saldo::decidir($this->base())['maximo_mu']);
        $this->assertSame(199_200, Saldo::decidir($this->base(['tope' => 10 ** 12]))['maximo_mu']);
        $this->assertSame(27_200, Saldo::decidir($this->base([
            'tope' => 10 ** 12, 'porcentaje' => 100, 'saldo_nutrition' => 200_000, 'muestras' => $this->muestras(1.0, 5, 200_000),
        ]))['maximo_mu']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function cantidadesMalas(): array
    {
        return [
            'cero' => [0], 'cero texto' => ['0'], 'negativo' => [-5], 'negativo texto' => ['-5'], 'decimal' => ['12.5'],
            'letras' => ['abc'], 'vacio' => [''], 'nulo' => [null], 'booleano' => [true], 'float' => [1000.0],
            'notacion cientifica' => ['1e3'], 'con signo mas' => ['+5'], 'array' => [[5]], 'espacios dentro' => ['1 000'],
            'hexadecimal' => ['0x10'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cantidadesMalas')]
    public function test_una_cantidad_mala_se_rechaza_con_el_control_cantidad(mixed $monto): void
    {
        $d = Saldo::decidir($this->base(['monto' => $monto]));

        $this->assertFalse($d['permitido']);
        $this->assertSame(Saldo::CTRL_CANTIDAD, $d['control']);
        $this->assertNotEmpty($d['motivo']);
    }

    public function test_una_cantidad_enorme_se_rechaza_sin_desbordar(): void
    {
        foreach (['999999999999999999999999999', (string) (Saldo::MAX_MU + 1), 9_223_372_036_854_775_807, '99999999999999999999999999999999999999999999'] as $monto) {
            $d = Saldo::decidir($this->base(['monto' => $monto, 'tope' => 10 ** 15]));

            $this->assertFalse($d['permitido'], (string) $monto);
            $this->assertSame(Saldo::CTRL_CANTIDAD, $d['control']);
            $this->assertStringContainsString('demasiado grande', $d['motivo']);
        }
    }

    public function test_saldos_enormes_no_desbordan_la_aritmetica(): void
    {
        $d = Saldo::decidir($this->base([
            'saldo_coder' => '99999999999999999999999', 'saldo_nutrition' => '99999999999999999999999', 'tope' => 10 ** 15, 'porcentaje' => 100,
            'muestras' => $this->muestras(1.0, 5, PHP_INT_MAX >> 4),
        ]));

        $this->assertIsInt($d['maximo_mu']);
        $this->assertGreaterThanOrEqual(0, $d['maximo_mu']);
        $this->assertLessThanOrEqual(Saldo::MAX_MU, $d['maximo_mu']);
    }

    public function test_una_cantidad_con_espacios_y_ceros_a_la_izquierda_se_lee(): void
    {
        $this->assertTrue(Saldo::decidir($this->base(['monto' => ' 0001000 ']))['permitido']);
        $this->assertTrue(Saldo::decidir($this->base(['monto' => '1000']))['permitido']);
    }

    public function test_sin_saldo_conocido_no_se_envia_nada(): void
    {
        foreach (['saldo_coder', 'saldo_nutrition'] as $clave) {
            $d = Saldo::decidir($this->base([$clave => null]));

            $this->assertFalse($d['permitido']);
            $this->assertSame(Saldo::CTRL_SALDO, $d['control']);
        }
    }

    public function test_la_tasa_necesita_al_menos_cuatro_muestras(): void
    {
        $this->assertNull(Saldo::tasa($this->muestras(1.0, 3)));
        $this->assertNull(Saldo::tasa([]));
        $this->assertNull(Saldo::tasa('no es una lista'));
        $this->assertEqualsWithDelta(1.0, Saldo::tasa($this->muestras(1.0, 4)), 0.0001);
    }

    public function test_la_tasa_necesita_un_tiempo_minimo(): void
    {
        $m = [];
        for ($i = 0; $i < 6; $i++) {
            $m[] = ['t' => 1000 + $i * 100, 'saldo' => 10_000 - $i * 100, 'dep' => 0];   // 500 s in total
        }

        $this->assertNull(Saldo::tasa($m));
        $this->assertEqualsWithDelta(1.0, Saldo::tasa($m, 4, 500), 0.0001);
    }

    public function test_la_tasa_no_cuenta_los_depositos_como_gasto(): void
    {
        // The balance falls 100 each 300 s and nutrition deposited 100 each time: no burn at all.
        $m = [];
        for ($i = 0; $i < 5; $i++) {
            $m[] = ['t' => 1000 + $i * 300, 'saldo' => 10_000 - $i * 100, 'dep' => $i * 100];
        }
        $this->assertNull(Saldo::tasa($m));

        // The same deposits and a burn of 1 MU/s on top.
        $m = [];
        for ($i = 0; $i < 5; $i++) {
            $m[] = ['t' => 1000 + $i * 300, 'saldo' => 10_000 - $i * 400, 'dep' => $i * 100];
        }
        $this->assertEqualsWithDelta(1.0, Saldo::tasa($m), 0.0001);
    }

    public function test_un_saldo_que_sube_o_no_cambia_da_tasa_desconocida(): void
    {
        $this->assertNull(Saldo::tasa($this->muestras(0.0)));
        $this->assertNull(Saldo::tasa($this->muestras(-1.0)));
    }

    public function test_un_intervalo_con_credito_se_descarta_si_quedan_suficientes(): void
    {
        $m = $this->muestras(1.0, 6);
        foreach ([3, 4, 5] as $i) {
            $m[$i]['saldo'] += 50_000;   // a refund: the balance rose, the interval before it is dropped
        }

        $tasa = Saldo::tasa($m);

        $this->assertNotNull($tasa);
        $this->assertEqualsWithDelta(1.0, $tasa, 0.0001);
        $this->assertNull(Saldo::tasa($this->conCredito()));
    }

    /** Four samples where one interval is a credit: three valid intervals are needed, only two remain. */
    private function conCredito(): array
    {
        $m = $this->muestras(1.0, 4);
        $m[2]['saldo'] += 50_000;

        return $m;
    }

    public function test_el_orden_de_las_muestras_no_importa(): void
    {
        $m = $this->muestras(2.0);

        $this->assertEqualsWithDelta(2.0, Saldo::tasa(array_reverse($m)), 0.0001);
    }

    public function test_las_muestras_mal_formadas_se_ignoran(): void
    {
        $m = $this->muestras(1.0, 5);
        $m[] = 'basura';
        $m[] = ['t' => 'x', 'saldo' => 5];
        $m[] = ['t' => 1, 'saldo' => 'abc'];
        $m[] = ['saldo' => 5];

        $this->assertEqualsWithDelta(1.0, Saldo::tasa($m), 0.0001);
    }

    public function test_aMu_lee_enteros_y_cadenas_de_digitos(): void
    {
        $this->assertSame(0, Saldo::aMu('000'));
        $this->assertSame(12, Saldo::aMu(' 12 '));
        $this->assertSame(12, Saldo::aMu(12));
        $this->assertNull(Saldo::aMu(-1));
        $this->assertNull(Saldo::aMu('1.5'));
        $this->assertNull(Saldo::aMu(1.5));
        $this->assertSame(Saldo::MAX_MU, Saldo::aMu('99999999999999999999'));
        $this->assertNull(Saldo::aMu('99999999999999999999', false));
        $this->assertSame(Saldo::MAX_MU, Saldo::aMu((string) Saldo::MAX_MU, false));
    }
}
