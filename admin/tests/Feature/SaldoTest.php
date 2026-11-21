<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Services\Coder\Coder;
use App\Services\Coder\CoderException;
use App\Services\Coder\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LectorEspecificacionFalso;
use Tests\Support\NodoFalsoCoder;
use Tests\Support\SondaSshFalsa;
use Tests\TestCase;

/**
 * App\Services\Coder\Saldo with a fake node: samples, transfers, audit and the status for the panel.
 * Defaults of the fake node: nutrition 1 000 000 MU, Coder 1 000 MU, price 30 MU per period (10 s).
 * So the default cap is 324 000 MU (24 h * 1.25), the target is 129 600 MU (12 h) and the top-up threshold is 64 800 MU.
 */
class SaldoTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private string $carpeta;

    private NodoFalsoCoder $nodo;

    private Coder $coder;

    private Saldo $saldo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/saldo-'.uniqid();
        mkdir($this->carpeta.'/coder', 0777, true);
        mkdir($this->carpeta.'/proyecto', 0777, true);
        config([
            'coder.directorio' => $this->carpeta.'/coder',
            'coder.secretos' => $this->carpeta.'/secretos',
            'coder.proyecto' => $this->carpeta.'/proyecto',
            'coder.lanzamiento.espera_ssh_s' => 0,
            'coder.lanzamiento.pausa_ssh_s' => 0,
        ]);
        $this->nodo = new NodoFalsoCoder;
        $this->coder = new Coder($this->nodo, new LectorEspecificacionFalso, new SondaSshFalsa);
        $this->saldo = new Saldo($this->nodo, $this->coder);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->carpeta));
        parent::tearDown();
    }

    private function lanzar(): void
    {
        $this->coder->lanzar(self::HASH);
    }

    /** Five samples, 300 s apart, with a burn of $tasa MU/s that ends at the balance of the fake node now. */
    private function semilla(float $tasa = 1.0, int $fin = 1_000_000): void
    {
        $ahora = now()->getTimestamp();
        $m = [];
        for ($i = 5; $i >= 1; $i--) {
            $m[] = ['t' => $ahora - $i * 300, 'saldo' => (int) ($fin + $i * 300 * $tasa), 'dep' => 0];
        }
        Ajuste::fijar('coder.muestras_saldo', $m);
    }

    private function eventos(string $evento): int
    {
        return Auditoria::query()->where('evento', $evento)->count();
    }

    // --- addBalance ---

    public function test_enviar_saldo_pasa_los_controles_deposita_y_lo_anota(): void
    {
        $this->lanzar();
        $this->semilla();

        $r = $this->saldo->addBalance('5000');

        $this->assertTrue($r['enviado']);
        $this->assertSame(5000, $r['mu']);
        $this->assertSame([[5, '5000']], $this->nodo->de('depositar'));
        $this->assertSame('6000', $this->nodo->saldoCoder);
        $this->assertSame('5000', Ajuste::obtener('coder.enviado_mu'));
        $this->assertSame('5000', Ajuste::obtener('coder.depositado_hijos_mu'));
        $this->assertSame(1, $this->eventos('coder.saldo_enviado'));
        $this->assertSame(5000, $this->saldo->status()['enviado_mu']);
    }

    public function test_dos_envios_suman_lo_enviado(): void
    {
        $this->lanzar();
        $this->semilla();

        $this->saldo->addBalance(1000);
        $this->saldo->addBalance(2000);

        $this->assertSame('3000', Ajuste::obtener('coder.enviado_mu'));
        $this->assertSame('3000', Ajuste::obtener('coder.depositado_hijos_mu'));
        $this->assertSame('4000', $this->nodo->saldoCoder);
    }

    public function test_un_envio_sobre_el_tope_se_rechaza_audita_y_no_toca_el_nodo(): void
    {
        $this->lanzar();
        $this->semilla();

        $r = $this->saldo->addBalance('1000000');

        $this->assertFalse($r['enviado']);
        $this->assertSame(Saldo::CTRL_TOPE, $r['control']);
        $this->assertSame(323_000, $r['maximo_mu']);
        $this->assertSame([], $this->nodo->de('depositar'));
        $this->assertSame('0', Ajuste::obtener('coder.enviado_mu'));
        $this->assertSame(1, $this->eventos('coder.saldo_rechazado'));
        $this->assertSame(Saldo::CTRL_TOPE, $this->saldo->status()['ultimo_rechazo']['control']);
    }

    public function test_cada_rechazo_manual_se_audita(): void
    {
        $this->lanzar();
        $this->semilla();

        $this->saldo->addBalance('1000000');
        $this->saldo->addBalance('1000000');

        $this->assertSame(2, $this->eventos('coder.saldo_rechazado'));
    }

    public function test_el_rechazo_audita_el_motivo(): void
    {
        $this->lanzar();
        $this->semilla();

        $this->saldo->addBalance('1000000');

        $fila = Auditoria::query()->where('evento', 'coder.saldo_rechazado')->firstOrFail();
        $this->assertSame('tope', $fila->datos['control']);
        $this->assertStringContainsString('tope total', $fila->datos['motivo']);
        $this->assertSame('manual', $fila->datos['origen']);
    }

    public function test_sin_muestras_la_vida_es_desconocida_y_no_se_envia_nada(): void
    {
        $this->lanzar();

        $r = $this->saldo->addBalance(100);

        $this->assertFalse($r['enviado']);
        $this->assertSame(Saldo::CTRL_VIDA, $r['control']);
        $this->assertSame([], $this->nodo->de('depositar'));
        $this->assertSame(1, $this->eventos('coder.saldo_rechazado'));
    }

    public function test_con_un_gasto_de_cero_la_vida_es_desconocida(): void
    {
        $this->lanzar();
        $this->semilla(0.0);

        $this->assertSame(Saldo::CTRL_VIDA, $this->saldo->addBalance(100)['control']);
    }

    public function test_el_porcentaje_limita_el_saldo_del_coder(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->saldo->guardarLimites('1000000000', 5, 48);

        $r = $this->saldo->addBalance(60_000);   // room: 5 % of 1 001 000 = 50 050, minus 1 000

        $this->assertSame(Saldo::CTRL_PORCENTAJE, $r['control']);
        $this->assertSame(49_050, $r['maximo_mu']);
        $this->assertTrue($this->saldo->addBalance(49_050)['enviado']);
        $this->assertFalse($this->saldo->addBalance(1)['enviado']);
    }

    public function test_la_vida_minima_limita_el_envio(): void
    {
        $this->lanzar();
        $this->nodo->saldoNutrition = '200000';
        $this->semilla(1.0, 200_000);
        $this->saldo->guardarLimites('1000000000', 100, 48);

        $r = $this->saldo->addBalance(27_201);

        $this->assertSame(Saldo::CTRL_VIDA, $r['control']);
        $this->assertTrue($this->saldo->addBalance(27_200)['enviado']);
        $this->assertSame('172800', $this->nodo->saldoNutrition);   // exactly 48 hours of burn
    }

    public function test_el_cliente_sube_el_tope_y_el_envio_pasa(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->assertFalse($this->saldo->addBalance(400_000)['enviado']);

        $limites = $this->saldo->guardarLimites('1000000', 50, 48);

        $this->assertSame(1_000_000, $limites['tope_mu']);
        $this->assertFalse($limites['tope_por_defecto']);
        $this->assertTrue($this->saldo->addBalance(400_000)['enviado']);
    }

    public function test_lo_enviado_cuenta_para_el_tope_en_el_siguiente_envio(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->saldo->guardarLimites('100000', 100, 48);

        $this->assertTrue($this->saldo->addBalance(49_000)['enviado']);   // sent 49 000, balance 50 000: 99 000 of 100 000
        $r = $this->saldo->addBalance(1_001);

        $this->assertSame(Saldo::CTRL_TOPE, $r['control']);
        $this->assertSame(1000, $r['maximo_mu']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function cantidadesMalas(): array
    {
        return [['0'], [0], ['-100'], [-100], ['abc'], [''], [null], ['1.5'], ['1e3'], [false], ['99999999999999999999999']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cantidadesMalas')]
    public function test_una_cantidad_mala_no_toca_el_nodo(mixed $monto): void
    {
        $this->lanzar();
        $this->semilla();

        $r = $this->saldo->addBalance($monto);

        $this->assertFalse($r['enviado']);
        $this->assertSame(Saldo::CTRL_CANTIDAD, $r['control']);
        $this->assertSame([], $this->nodo->de('depositar'));
        $this->assertSame('0', Ajuste::obtener('coder.enviado_mu'));
    }

    public function test_si_el_nodo_rechaza_el_deposito_no_se_anota_nada(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->rechazoDeposito = 'insufficient balance';

        $r = $this->saldo->addBalance(5000);

        $this->assertFalse($r['enviado']);
        $this->assertSame(Saldo::CTRL_DEPOSITO, $r['control']);
        $this->assertStringContainsString('insufficient balance', $r['motivo']);
        $this->assertSame('0', Ajuste::obtener('coder.enviado_mu'));
        $this->assertSame('0', Ajuste::obtener('coder.depositado_hijos_mu') ?? '0');
        $this->assertSame(1, $this->eventos('coder.saldo_fallo'));
        $this->assertSame(0, $this->eventos('coder.saldo_enviado'));
    }

    public function test_si_el_nodo_no_contesta_al_depositar_no_se_anota_nada(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->falloDeposito = true;

        $r = $this->saldo->addBalance(5000);

        $this->assertFalse($r['enviado']);
        $this->assertSame(Saldo::CTRL_DEPOSITO, $r['control']);
        $this->assertSame('0', Ajuste::obtener('coder.enviado_mu'));
        $this->assertSame(1, $this->eventos('coder.saldo_fallo'));
    }

    public function test_un_fallo_de_deposito_no_gasta_el_tope(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->rechazoDeposito = 'no';
        $this->saldo->addBalance(5000);
        $this->nodo->rechazoDeposito = null;

        $this->assertSame(323_000, $this->saldo->addBalance(1)['maximo_mu'] + 1);
    }

    public function test_sin_coder_en_marcha_enviar_saldo_es_un_error_de_usuario(): void
    {
        $this->expectException(CoderException::class);
        $this->expectExceptionMessage('No hay un Coder en marcha');

        $this->saldo->addBalance(100);
    }

    public function test_un_coder_de_otro_dispositivo_no_recibe_saldo(): void
    {
        $this->lanzar();
        $this->semilla();
        Ajuste::fijar('coder.instancia', 'otro-dispositivo');

        try {
            $this->saldo->addBalance(100);
            $this->fail('A CoderException was expected.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('otro dispositivo', $e->getMessage());
        }
        $this->assertSame([], $this->nodo->de('depositar'));
    }

    public function test_si_el_nodo_no_da_el_saldo_enviar_es_un_error_y_no_deposita(): void
    {
        $this->lanzar();
        $this->nodo->saldoNutrition = null;

        $this->expectException(CoderException::class);
        try {
            $this->saldo->addBalance(100);
        } finally {
            $this->assertSame([], $this->nodo->de('depositar'));
        }
    }

    // --- Limits ---

    public function test_los_limites_por_defecto(): void
    {
        $l = $this->saldo->limites();

        $this->assertNull($l['tope_mu']);   // the price is not known yet
        $this->assertTrue($l['tope_por_defecto']);
        $this->assertSame(20, $l['porcentaje']);
        $this->assertSame(48, $l['horas_minimas']);
    }

    public function test_el_tope_por_defecto_son_24_horas_con_margen_al_precio_de_ahora(): void
    {
        $this->lanzar();
        $this->saldo->muestrear();   // reads celaut cost: 30 MU per period

        $l = $this->saldo->limites();

        $this->assertSame(324_000, $l['tope_mu']);   // 30 * 8640 * 1.25
        $this->assertTrue($l['tope_por_defecto']);
        $this->assertSame([[self::HASH]], $this->nodo->de('costePorHash'));
    }

    public function test_el_precio_se_lee_una_vez_mientras_vale(): void
    {
        $this->lanzar();
        $this->saldo->muestrear();
        $this->saldo->muestrear();

        $this->assertCount(1, $this->nodo->de('costePorHash'));

        $this->travel(11)->minutes();
        $this->saldo->muestrear();

        $this->assertCount(2, $this->nodo->de('costePorHash'));
    }

    public function test_si_el_precio_no_se_puede_leer_no_hay_tope_y_no_se_envia_nada(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->rechazoCoste = 'sin precio';

        $r = $this->saldo->addBalance(100);

        $this->assertFalse($r['enviado']);
        $this->assertSame(Saldo::CTRL_TOPE, $r['control']);
    }

    public function test_guardar_limites_valida_y_no_cambia_nada_si_uno_es_malo(): void
    {
        $malos = [
            ['abc', 20, 48], ['0', 20, 48], ['-5', 20, 48], ['1.5', 20, 48], ['99999999999999999999', 20, 48],
            ['', 0, 48], ['', 101, 48], ['', 'x', 48], ['', 20, 'x'], ['', 20, 8761], ['', 20, -1], ['', 20, ''],
        ];
        foreach ($malos as [$tope, $porcentaje, $horas]) {
            try {
                $this->saldo->guardarLimites($tope, $porcentaje, $horas);
                $this->fail('A CoderException was expected for '.json_encode([$tope, $porcentaje, $horas]));
            } catch (CoderException) {
                // expected
            }
        }

        $this->assertNull(Ajuste::obtener('coder.tope_mu'));
        $this->assertNull(Ajuste::obtener('coder.porcentaje_max'));
        $this->assertSame(20, $this->saldo->limites()['porcentaje']);
    }

    public function test_guardar_limites_acepta_valores_buenos_y_audita_el_cambio(): void
    {
        $l = $this->saldo->guardarLimites(' 500000 ', '30', '0');

        $this->assertSame(500_000, $l['tope_mu']);
        $this->assertSame(30, $l['porcentaje']);
        $this->assertSame(0, $l['horas_minimas']);
        $this->assertGreaterThanOrEqual(3, $this->eventos('ajuste.cambiado'));
    }

    public function test_un_tope_vacio_vuelve_al_de_por_defecto(): void
    {
        $this->saldo->guardarLimites('500000', 20, 48);

        $l = $this->saldo->guardarLimites('', 20, 48);

        $this->assertTrue($l['tope_por_defecto']);
        $this->assertNull(Ajuste::obtener('coder.tope_mu'));
    }

    // --- Samples ---

    public function test_muestrear_guarda_una_muestra_con_el_saldo_y_lo_depositado(): void
    {
        $this->lanzar();
        Saldo::anotarDeposito(700);

        $l = $this->saldo->muestrear();

        $this->assertSame(1_000_000, $l['nutrition']);
        $this->assertSame(1000, $l['coder']);
        $m = Ajuste::obtener('coder.muestras_saldo');
        $this->assertCount(1, $m);
        $this->assertSame(1_000_000, $m[0]['saldo']);
        $this->assertSame(700, $m[0]['dep']);
        $this->assertSame('1000', Ajuste::obtener('coder.saldo_mu'));
    }

    public function test_muestrear_sin_coder_toma_solo_el_saldo_del_servicio(): void
    {
        $l = $this->saldo->muestrear();

        $this->assertNull($l['coder']);
        $this->assertSame([], $this->nodo->de('metricas'));
        $this->assertCount(1, Ajuste::obtener('coder.muestras_saldo'));
    }

    public function test_la_lista_de_muestras_tiene_limite_y_olvida_las_viejas(): void
    {
        config(['coder.saldo.muestras_maximo' => 3]);
        Ajuste::fijar('coder.muestras_saldo', [['t' => now()->getTimestamp() - 13 * 3600, 'saldo' => 5, 'dep' => 0]]);

        for ($i = 0; $i < 5; $i++) {
            $this->saldo->muestrear();
            $this->travel(5)->minutes();
        }

        $m = Ajuste::obtener('coder.muestras_saldo');
        $this->assertCount(3, $m);
        $this->assertNotContains(5, array_column($m, 'saldo'));
    }

    public function test_muestrear_sin_respuesta_del_nodo_es_un_error_y_no_guarda_nada(): void
    {
        $this->nodo->saldoNutrition = null;

        try {
            $this->saldo->muestrear();
            $this->fail('A CoderException was expected.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('No se pudo leer el saldo', $e->getMessage());
        }
        $this->assertNull(Ajuste::obtener('coder.muestras_saldo'));
    }

    public function test_si_el_saldo_del_coder_no_se_lee_es_un_error(): void
    {
        $this->lanzar();
        $this->nodo->falloMetricas = true;

        $this->expectException(CoderException::class);
        $this->saldo->muestrear();
    }

    public function test_un_lanzamiento_borra_las_muestras(): void
    {
        $this->semilla();

        $this->lanzar();

        $this->assertSame([], Ajuste::obtener('coder.muestras_saldo'));
    }

    public function test_anotar_depositos_suma_y_no_baja_de_cero(): void
    {
        Saldo::anotarDeposito(500);
        Saldo::anotarDeposito(500);
        Saldo::anotarDeposito(-200);
        $this->assertSame('800', Ajuste::obtener('coder.depositado_hijos_mu'));

        Saldo::anotarDeposito(-5000);
        $this->assertSame('0', Ajuste::obtener('coder.depositado_hijos_mu'));
    }

    public function test_las_muestras_y_los_saldos_no_llenan_la_auditoria(): void
    {
        $this->lanzar();
        $antes = Auditoria::query()->count();

        for ($i = 0; $i < 4; $i++) {
            $this->saldo->muestrear();
        }

        $this->assertSame($antes, Auditoria::query()->count());
    }

    // --- topUpToTarget and vigilar ---

    public function test_el_vigilante_recarga_al_objetivo_cuando_todo_pasa(): void
    {
        $this->lanzar();
        $this->semilla();

        $r = $this->saldo->topUpToTarget();

        $this->assertSame('enviado', $r['accion']);
        $this->assertSame(128_600, $r['mu']);
        $this->assertSame('129600', $this->nodo->saldoCoder);   // 12 hours at 30 MU per 10 s
        $fila = Auditoria::query()->where('evento', 'coder.saldo_enviado')->firstOrFail();
        $this->assertSame('objetivo', $fila->datos['origen']);
    }

    public function test_con_saldo_suficiente_no_hace_nada(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->saldoCoder = '129600';

        $this->assertSame('no_necesario', $this->saldo->topUpToTarget()['accion']);
        $this->assertSame([], $this->nodo->de('depositar'));
        $this->assertSame(0, $this->eventos('coder.saldo_rechazado'));
    }

    public function test_justo_en_el_umbral_no_recarga_y_un_mu_menos_si(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->saldoCoder = '64800';
        $this->assertSame('no_necesario', $this->saldo->topUpToTarget()['accion']);

        $this->nodo->saldoCoder = '64799';
        $this->assertSame('enviado', $this->saldo->topUpToTarget()['accion']);
        $this->assertSame('129600', $this->nodo->saldoCoder);
    }

    public function test_sin_vida_conocida_el_vigilante_no_envia_y_audita_una_vez(): void
    {
        $this->lanzar();

        $a = $this->saldo->topUpToTarget();
        $b = $this->saldo->topUpToTarget();

        $this->assertSame('rechazado', $a['accion']);
        $this->assertSame('rechazado', $b['accion']);
        $this->assertSame([], $this->nodo->de('depositar'));
        $this->assertSame(1, $this->eventos('coder.saldo_rechazado'));   // the loop repeats it: one audit row per hour
        $this->assertNotNull($this->saldo->status()['ultimo_rechazo']);
    }

    public function test_el_rechazo_repetido_se_vuelve_a_auditar_pasada_una_hora(): void
    {
        $this->lanzar();
        $this->saldo->topUpToTarget();

        $this->travel(61)->minutes();
        $this->saldo->topUpToTarget();

        $this->assertSame(2, $this->eventos('coder.saldo_rechazado'));
    }

    public function test_un_rechazo_de_otro_control_se_audita_aunque_sea_pronto(): void
    {
        $this->lanzar();
        $this->saldo->topUpToTarget();   // life unknown
        $this->semilla();
        $this->saldo->guardarLimites('5000', 20, 48);
        $this->saldo->topUpToTarget();   // cap

        $this->assertSame(2, $this->eventos('coder.saldo_rechazado'));
    }

    public function test_el_vigilante_corta_el_envio_al_tope_y_nunca_lo_pasa(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->saldo->guardarLimites('50000', 100, 48);

        $a = $this->saldo->topUpToTarget();

        $this->assertSame('enviado', $a['accion']);
        $this->assertSame(49_000, $a['mu']);
        $this->assertSame('50000', $this->nodo->saldoCoder);

        $b = $this->saldo->topUpToTarget();   // 50 000 is under the threshold, but there is no room under the cap

        $this->assertSame('rechazado', $b['accion']);
        $this->assertSame('50000', $this->nodo->saldoCoder);
        $this->assertCount(1, $this->nodo->de('depositar'));
    }

    public function test_un_margen_muy_pequeno_no_justifica_un_envio(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->saldo->guardarLimites('5000', 100, 48);   // room 4 000, less than one hour (10 800)

        $r = $this->saldo->topUpToTarget();

        $this->assertSame('rechazado', $r['accion']);
        $this->assertSame([], $this->nodo->de('depositar'));
    }

    public function test_el_vigilante_corta_el_envio_al_porcentaje(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->saldo->guardarLimites('1000000000', 5, 48);

        $a = $this->saldo->topUpToTarget();
        $b = $this->saldo->topUpToTarget();

        $this->assertSame(49_050, $a['mu']);
        $this->assertSame('rechazado', $b['accion']);
        $this->assertSame('50050', $this->nodo->saldoCoder);   // 5 % of the joint balance 1 001 000
    }

    public function test_el_vigilante_corta_el_envio_a_la_vida_minima(): void
    {
        $this->lanzar();
        $this->nodo->saldoNutrition = '200000';
        $this->semilla(1.0, 200_000);
        $this->saldo->guardarLimites('1000000000', 100, 48);

        $r = $this->saldo->topUpToTarget();

        $this->assertSame(27_200, $r['mu']);
        $this->assertSame('172800', $this->nodo->saldoNutrition);
    }

    public function test_el_vigilante_registra_un_fallo_de_deposito_sin_enviar(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->nodo->rechazoDeposito = 'insufficient balance';

        $r = $this->saldo->topUpToTarget();

        $this->assertSame('fallo', $r['accion']);
        $this->assertSame('0', Ajuste::obtener('coder.enviado_mu'));
        $this->assertSame(1, $this->eventos('coder.saldo_fallo'));
    }

    public function test_sin_coder_el_vigilante_solo_toma_muestras(): void
    {
        $r = $this->saldo->vigilar();

        $this->assertSame('ninguna', $r['accion']);
        $this->assertSame([], $this->nodo->de('depositar'));
        $this->assertCount(1, Ajuste::obtener('coder.muestras_saldo'));
    }

    public function test_con_un_coder_ajeno_el_vigilante_no_llama_al_nodo(): void
    {
        $this->lanzar();
        Ajuste::fijar('coder.instancia', 'otro-dispositivo');
        $this->nodo->llamadas = [];

        $this->assertSame('ajeno', $this->saldo->vigilar()['accion']);
        $this->assertSame('ajeno', $this->saldo->topUpToTarget()['accion']);
        $this->assertSame([], $this->nodo->llamadas);
    }

    public function test_si_el_nodo_no_contesta_el_vigilante_no_falla_ni_envia(): void
    {
        $this->lanzar();
        $this->nodo->saldoNutrition = null;

        $r = $this->saldo->vigilar();

        $this->assertSame('sin_nodo', $r['accion']);
        $this->assertSame([], $this->nodo->de('depositar'));
    }

    public function test_el_vigilante_con_coder_en_error_no_envia(): void
    {
        $this->lanzar();
        $this->nodo->pool = [];
        $this->coder->vigilar();   // the Coder died: state "error"
        $this->nodo->llamadas = [];

        $this->assertSame('ninguna', $this->saldo->vigilar()['accion']);
        $this->assertSame([], $this->nodo->de('depositar'));
    }

    public function test_un_cerrojo_ocupado_no_deja_enviar_a_la_vez(): void
    {
        $this->lanzar();
        $this->semilla();
        $cerrojo = \Illuminate\Support\Facades\Cache::lock('coder-saldo', 60);
        $this->assertTrue($cerrojo->get());

        $this->assertSame('ocupado', $this->saldo->topUpToTarget()['accion']);
        try {
            $this->saldo->addBalance(100);
            $this->fail('A CoderException was expected.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('otra operación de saldo', $e->getMessage());
        }
        $this->assertSame([], $this->nodo->de('depositar'));
        $cerrojo->release();
        $this->assertTrue($this->saldo->addBalance(100)['enviado']);
    }

    // --- status ---

    public function test_el_estado_da_las_horas_del_coder_y_del_servicio_sin_llamar_al_nodo(): void
    {
        $this->lanzar();
        $this->semilla();
        $this->saldo->topUpToTarget();
        $this->nodo->llamadas = [];

        $s = $this->saldo->status();

        $this->assertSame([], $this->nodo->llamadas);
        $this->assertSame(129_600, $s['saldo_coder_mu']);
        $this->assertSame(871_400, $s['saldo_nutrition_mu']);
        $this->assertEqualsWithDelta(12.0, $s['horas_coder'], 0.05);
        $this->assertEqualsWithDelta(242.1, $s['horas_nutrition'], 0.1);
        $this->assertEqualsWithDelta(1.0, $s['tasa_mu_s'], 0.001);
        $this->assertSame(128_600, $s['enviado_mu']);
        $this->assertSame(324_000, $s['tope_mu']);
        $this->assertTrue($s['tope_por_defecto']);
        $this->assertSame(20, $s['porcentaje']);
        $this->assertSame(48, $s['horas_minimas']);
        $this->assertSame(30, $s['precio_periodo_mu']);
        $this->assertNull($s['ultimo_rechazo']);
        $this->assertGreaterThan(0, $s['maximo_mu']);
    }

    public function test_el_estado_sin_datos_da_nulos_y_no_falla(): void
    {
        $s = $this->saldo->status();

        $this->assertNull($s['saldo_coder_mu']);
        $this->assertNull($s['horas_coder']);
        $this->assertNull($s['horas_nutrition']);
        $this->assertNull($s['tasa_mu_s']);
        $this->assertNull($s['tope_mu']);
        $this->assertSame(0, $s['muestras']);
        $this->assertSame(0, $s['enviado_mu']);
        $this->assertSame(0, $s['maximo_mu']);
    }

    public function test_el_estado_muestra_el_ultimo_rechazo(): void
    {
        $this->lanzar();
        $this->saldo->addBalance(100);

        $r = $this->saldo->status()['ultimo_rechazo'];

        $this->assertSame(Saldo::CTRL_VIDA, $r['control']);
        $this->assertSame('manual', $r['origen']);
        $this->assertNotEmpty($r['motivo']);
    }

    public function test_parar_el_coder_borra_el_ultimo_rechazo_y_el_precio(): void
    {
        $this->lanzar();
        $this->saldo->addBalance(100);

        $this->coder->parar();

        $this->assertNull($this->saldo->status()['ultimo_rechazo']);
        $this->assertNull($this->saldo->status()['precio_periodo_mu']);
    }
}
