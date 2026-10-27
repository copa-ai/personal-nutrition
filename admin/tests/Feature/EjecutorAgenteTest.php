<?php

namespace Tests\Feature;

use App\Enums\EstadoMenu;
use App\Jobs\GenerarMenu;
use App\Models\ConsumoMenu;
use App\Models\Menu;
use App\Services\Menu\EjecutorAgente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreaHogar;
use Tests\TestCase;

class EjecutorAgenteTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 10:00:00');
        Queue::fake();
        $this->carpeta = sys_get_temp_dir().'/agente-test-'.uniqid();
        File::makeDirectory($this->carpeta.'/workspace', 0775, true);
        config([
            'agente.carpeta' => $this->carpeta, 'agente.remoto.url' => 'http://agente:9000', 'agente.remoto.secreto' => 'secreto-de-prueba-123',
            'agente.remoto.espera_ms' => 0,
        ]);
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::EnCola]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);
        parent::tearDown();
    }

    private function resultado(): string
    {
        return $this->carpeta."/workspace/resultado-{$this->menu->id}.json";
    }

    private function lineas(array ...$eventos): string
    {
        return implode('', array_map(fn (array $e): string => json_encode($e, JSON_UNESCAPED_UNICODE)."\n", $eventos));
    }

    /**
     * Runner simulado. $alConsultar se ejecuta en la primera consulta y hace de «agente» (p. ej. deja el resultado en disco).
     * $respuesta pisa los campos de la respuesta final (codigo, interrumpida…); $salida es el texto que «imprime» claude.
     */
    private function runner(?callable $alConsultar = null, array $respuesta = [], ?string $salida = null): void
    {
        $salida ??= $this->lineas(
            ['type' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5'],
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Planificando']]]],
            ['type' => 'result', 'result' => 'Menú entregado', 'num_turns' => 3],
        );
        // Http::fake acumula: sin esto, un segundo runner() no sustituiría al primero.
        app()->forgetInstance(HttpFactory::class);
        Http::clearResolvedInstances();
        Http::fake(function (Request $r) use ($alConsultar, $respuesta, $salida) {
            if ($r->method() === 'POST') {
                return Http::response(['id' => 'run1'], 202);
            }
            if ($r->method() === 'GET') {
                $alConsultar && $alConsultar();

                return Http::response($respuesta + ['estado' => 'terminado', 'codigo' => 0, 'chunks' => [['out', $salida]], 'siguiente' => 1]);
            }

            return Http::response(['ok' => true]);
        });
    }

    /** El «agente» deja una entrega válida y con consumo, como haría menu.py entregar. */
    private function entregaDelAgente(): \Closure
    {
        return function (): void {
            $this->menu->refresh();
            $entrega = $this->entregaValida();
            $entrega['entrega'] = ['completo' => true, 'version' => 3];
            $entrega['consumo'] = ['totales' => ['respuestas' => 1], 'filas' => [[
                'mensaje_id' => 'm1', 'fase' => 'planificacion', 'modelo' => 'claude-sonnet-5-5', 'input_tokens' => 12000, 'output_tokens' => 3000,
            ]]];
            file_put_contents($this->resultado(), json_encode($entrega));
        };
    }

    private function ejecutar(): Menu
    {
        return app(EjecutorAgente::class)->ejecutar($this->menu->fresh());
    }

    public function test_un_menu_correcto_termina_importado_con_su_consumo_y_su_registro(): void
    {
        $this->runner($this->entregaDelAgente());

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Terminado, $m->estado);
        $this->assertNull($m->error);
        $this->assertSame([null, null, 1], [$m->ejecucion_id, $m->proceso_pid, $m->ciclo]);
        $this->assertSame(7, $m->diasConMenu());
        $this->assertSame('0.054', (string) ConsumoMenu::query()->where('menu_id', $m->id)->sole()->coste_total);
        $log = file_get_contents($m->archivo_log);
        $this->assertStringContainsString('▶ Sesión iniciada (claude-sonnet-5-5)', $log);
        $this->assertStringContainsString('✔ Terminado (3 turnos)', $log);
    }

    public function test_el_encargo_se_escribe_con_formato_salida_y_datos_y_se_guarda_en_el_menu(): void
    {
        $this->runner($this->entregaDelAgente());

        $m = $this->ejecutar();

        $encargo = json_decode(file_get_contents($this->carpeta."/encargos/encargo-{$m->id}.json"), true);
        $this->assertSame(['encargo-menu', 3, $m->id, "workspace/resultado-{$m->id}.json"], [$encargo['formato'], $encargo['version'], $encargo['encargo_id'], $encargo['salida']]);
        $this->assertSame(['Marta', 'Javier'], array_column($encargo['perfiles'], 'nombre'));
        $this->assertSame($m->encargo['semana'], $encargo['semana']);
    }

    public function test_el_prompt_es_corto_y_no_lleva_datos(): void
    {
        $this->runner($this->entregaDelAgente());

        $this->ejecutar();

        $prompt = Http::recorded()->first(fn ($par) => $par[0]->method() === 'POST')[0]->data()['prompt'];
        $this->assertStringStartsWith("Realiza el trabajo {$this->menu->id}. Usa la skill planificar-menu en modo encargo", $prompt);
        $this->assertStringNotContainsString('Marta', $prompt);
        $this->assertLessThan(400, strlen($prompt));
    }

    public function test_limite_de_uso_deja_el_menu_en_espera_y_programa_el_reintento(): void
    {
        $this->runner(null, ['codigo' => 1], "You've hit your session limit · resets 3:20pm (UTC)\n");

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::EnEspera, $m->estado);
        $this->assertSame('2026-11-01 15:22:00', $m->proximo_ciclo_en->toDateTimeString());   // 15:20 + margen de 2 min
        $this->assertStringContainsString('Se agotó la ventana de uso', $m->error);
        Queue::assertPushed(GenerarMenu::class, fn (GenerarMenu $j): bool => $j->menuId === $m->id && $j->delay !== null);
    }

    public function test_si_se_pidio_detener_el_limite_de_uso_no_reprograma(): void
    {
        $this->runner(function () {
            $this->menu->update(['detener_pedido' => true]);
        }, ['codigo' => 1], "You've hit your session limit · resets 3:20pm (UTC)\n");

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Terminado, $m->estado);
        $this->assertFalse($m->detener_pedido);
        Queue::assertNothingPushed();
    }

    public function test_el_reintento_tras_el_limite_rehace_el_menu_y_conserva_el_registro(): void
    {
        $this->runner(null, ['codigo' => 1], "You've hit your session limit · resets 3:20pm (UTC)\n");
        $this->ejecutar();

        $this->runner($this->entregaDelAgente());
        $m = $this->ejecutar();

        $this->assertSame([EstadoMenu::Terminado, 2, null], [$m->estado, $m->ciclo, $m->error]);
        $log = file_get_contents($m->archivo_log);
        $this->assertStringContainsString('hit your session limit', $log);
        $this->assertStringContainsString('===== Ciclo 2', $log);
    }

    public function test_un_codigo_de_salida_distinto_de_cero_sin_limite_es_un_fallo(): void
    {
        $this->runner(null, ['codigo' => 1], "algo salio mal\n");

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('código 1', $m->error);
        $this->assertNull($m->ejecucion_id);
    }

    public function test_terminar_bien_sin_dejar_resultado_es_un_fallo_con_el_ultimo_mensaje(): void
    {
        $this->runner(null, [], $this->lineas(['type' => 'result', 'result' => 'No puedo cumplir la restricción de Marta.']));

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('no se generó el resultado', $m->error);
        $this->assertStringContainsString('No puedo cumplir la restricción de Marta.', $m->error);
    }

    public function test_un_resultado_antiguo_no_cuenta_como_entrega_de_esta_vez(): void
    {
        file_put_contents($this->resultado(), json_encode(['dias' => []]));
        $this->runner();

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('no se generó el resultado', $m->error);
    }

    public function test_una_entrega_que_incumple_el_contrato_deja_el_menu_fallido_y_sin_contenido(): void
    {
        $this->runner(function () {
            $this->menu->refresh();
            $mala = $this->entregaValida();
            $mala['explicaciones'] = [];
            file_put_contents($this->resultado(), json_encode($mala));
        });

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('La entrega del agente no es válida', $m->error);
        $this->assertStringContainsString('una explicación por perfil', $m->error);
        $this->assertNull($m->contenido);
    }

    public function test_un_resultado_que_no_es_json_es_un_fallo(): void
    {
        $this->runner(function () {
            file_put_contents($this->resultado(), '{no es json');
        });

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('no es un JSON válido', $m->error);
    }

    public function test_si_falta_informacion_falla_antes_de_llamar_al_agente(): void
    {
        $this->hogar->perfiles()->update(['activo' => false]);
        Http::fake();

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('ningún perfil activo', $m->error);
        Http::assertNothingSent();
    }

    public function test_si_el_agente_se_reinicia_a_mitad_el_menu_falla_con_un_mensaje_claro(): void
    {
        $this->runner(null, ['codigo' => -1, 'interrumpida' => true]);

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('El agente se reinició', $m->error);
        $this->assertNull($m->ejecucion_id);
    }

    public function test_si_se_fuerza_la_parada_mientras_corre_no_se_pisa_con_fallido(): void
    {
        $this->runner(function () {
            $this->menu->update(['estado' => EstadoMenu::Borrador, 'ejecucion_id' => null]);   // lo que hace «Forzar parada»
        }, ['codigo' => -9, 'interrumpida' => true]);

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Borrador, $m->estado);
        $this->assertNull($m->error);
    }

    public function test_el_id_de_la_ejecucion_remota_se_guarda_mientras_corre(): void
    {
        $visto = null;
        $this->runner(function () use (&$visto) {
            $visto = $this->menu->fresh()->ejecucion_id;
        });

        $this->ejecutar();

        $this->assertSame('run1', $visto);
    }

    public function test_el_job_solo_ejecuta_un_menu_en_cola_o_en_espera_que_ya_toca(): void
    {
        $casos = [
            'en cola' => [['estado' => EstadoMenu::EnCola], true],
            'en espera que ya toca' => [['estado' => EstadoMenu::EnEspera, 'proximo_ciclo_en' => now()->subMinute()], true],
            'en espera casi a la hora' => [['estado' => EstadoMenu::EnEspera, 'proximo_ciclo_en' => now()->addSeconds(3)], true],
            'en espera, aviso viejo' => [['estado' => EstadoMenu::EnEspera, 'proximo_ciclo_en' => now()->addHour()], false],
            'borrador' => [['estado' => EstadoMenu::Borrador], false],
            'en marcha' => [['estado' => EstadoMenu::EnMarcha], false],
            'terminado' => [['estado' => EstadoMenu::Terminado], false],
        ];

        foreach ($casos as $nombre => [$campos, $debeEjecutar]) {
            $this->menu->update($campos);
            $ejecutor = \Mockery::mock(EjecutorAgente::class);
            $ejecutor->shouldReceive('ejecutar')->times($debeEjecutar ? 1 : 0);

            (new GenerarMenu($this->menu->id))->handle($ejecutor);

            $ejecutor->mockery_verify();
            $this->addToAssertionCount(1);
        }
    }

    public function test_el_job_ignora_un_menu_que_ya_no_existe(): void
    {
        $ejecutor = \Mockery::mock(EjecutorAgente::class);
        $ejecutor->shouldReceive('ejecutar')->never();

        (new GenerarMenu(999999))->handle($ejecutor);

        $this->addToAssertionCount(1);
    }

    public function test_si_la_cola_mata_el_job_el_menu_no_se_queda_en_marcha_y_se_cancela_el_agente(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->menu->update(['estado' => EstadoMenu::EnMarcha, 'ejecucion_id' => 'run1']);

        (new GenerarMenu($this->menu->id))->failed(new \RuntimeException('tiempo agotado'));

        $m = $this->menu->fresh();
        $this->assertSame(EstadoMenu::Fallido, $m->estado);
        $this->assertStringContainsString('La tanda se interrumpió: tiempo agotado', $m->error);
        $this->assertNull($m->ejecucion_id);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ejecuciones/run1'));
    }

    public function test_failed_no_toca_un_menu_que_no_estaba_en_marcha(): void
    {
        Http::fake();
        $this->menu->update(['estado' => EstadoMenu::Terminado]);

        (new GenerarMenu($this->menu->id))->failed(new \RuntimeException('x'));

        $this->assertSame(EstadoMenu::Terminado, $this->menu->fresh()->estado);
        Http::assertNothingSent();
    }

    /** Deja el menú ya generado y activo, listo para pedirle una tarea nueva al agente. */
    private function menuGenerado(): void
    {
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $this->menu->importarEntrega($this->entregaValida());
        $this->menu->update(['estado' => EstadoMenu::Terminado]);
    }

    public function test_un_intercambio_del_agente_se_importa_en_los_cambios_del_hogar_sin_tocar_el_menu(): void
    {
        $this->menuGenerado();
        $contenido = $this->menu->fresh()->contenido;
        $this->menu->fresh()->pedirIntercambio('Comimos pizza en vez de la comida del lunes');
        $this->runner(function () {
            $this->menu->refresh();
            $toma = $this->entregaValida()['dias'][0]['comida'];
            $toma['opciones'][0]['titulo'] = 'Cambio A';
            file_put_contents($this->resultado(), json_encode(['entrega' => ['completo' => true, 'version' => 3], 'cambios' => ['2026-11-02/comida' => $toma],
                'consumo' => ['totales' => ['respuestas' => 1], 'filas' => [[
                    'mensaje_id' => 'i1', 'fase' => 'planificacion', 'modelo' => 'claude-sonnet-5-5', 'input_tokens' => 1000, 'output_tokens' => 500,
                ]]]]));
        });

        $m = $this->ejecutar();

        $this->assertSame([EstadoMenu::Terminado, null, 'intercambiar'], [$m->estado, $m->error, $m->tarea]);
        $this->assertSame($contenido['dias'], $m->contenido['dias']);
        $this->assertSame('Cambio A', $this->hogar->fresh()->cambios['2026-11-02/comida']['opciones'][0]['titulo']);
        $this->assertSame('Cambio A', $m->opcionesDe('2026-11-02', 'comida')[0]['titulo']);
        $this->assertSame(1, ConsumoMenu::query()->where('menu_id', $m->id)->where('mensaje_id', 'i1')->count());
        $encargo = json_decode(file_get_contents($this->carpeta."/encargos/encargo-{$m->id}.json"), true);
        $this->assertSame(['intercambiar', 'Comimos pizza en vez de la comida del lunes'], [$encargo['tarea'], $encargo['instruccion']]);
        $this->assertArrayNotHasKey('menu_activo', $m->encargo);
    }

    public function test_un_intercambio_invalido_deja_el_menu_terminado_con_el_error_y_sin_cambios(): void
    {
        $this->menuGenerado();
        $this->menu->fresh()->pedirIntercambio('Comimos pizza');
        $this->runner(fn () => file_put_contents($this->resultado(), json_encode(['entrega' => ['completo' => true, 'version' => 3], 'cambios' => ['2000-01-01/comida' => []]])));

        $m = $this->ejecutar();

        $this->assertSame(EstadoMenu::Terminado, $m->estado);
        $this->assertStringContainsString('La entrega del agente no es válida', $m->error);
        $this->assertNull($this->hogar->fresh()->cambios);
    }

    public function test_una_edicion_del_agente_se_importa(): void
    {
        $this->menuGenerado();
        $this->menu->fresh()->pedirEdicion('Sin pescado');
        $this->runner(function () {
            $this->menu->refresh();
            $entrega = $this->entregaValida();
            $entrega['dias'][0]['comida']['opciones'][0]['titulo'] = 'Gazpacho';
            file_put_contents($this->resultado(), json_encode($entrega));
        });

        $m = $this->ejecutar();

        $this->assertSame([EstadoMenu::Terminado, null], [$m->estado, $m->error]);
        $this->assertSame('Gazpacho', $m->contenido['dias'][0]['comida']['opciones'][0]['titulo']);
    }
}
