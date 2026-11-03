<?php

namespace Tests\Feature;

use App\Services\Agente\AlmacenAgente;
use App\Services\Claude\EjecutorClaude;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EjecutorClaudeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['agente.remoto.url' => 'http://agente:9000', 'agente.remoto.secreto' => 'secreto-de-prueba-123', 'agente.remoto.espera_ms' => 0, 'agente.modelo' => 'claude-sonnet-5-5']);
    }

    /** Runner simulado: el alta devuelve $alta y cada consulta GET devuelve la siguiente respuesta de $consultas (la última se repite). */
    private function runner(array $consultas, array $alta = ['id' => 'abc123'], int $estadoAlta = 202): void
    {
        $n = 0;
        Http::fake(function (Request $r) use ($consultas, $alta, $estadoAlta, &$n) {
            return match ($r->method()) {
                'POST' => Http::response($alta, $estadoAlta),
                'GET' => Http::response($consultas[min($n++, count($consultas) - 1)]),
                default => Http::response(['ok' => true]),
            };
        });
    }

    private function enviados(string $metodo): array
    {
        return Http::recorded()->filter(fn ($par) => $par[0]->method() === $metodo)->map(fn ($par) => $par[0])->values()->all();
    }

    public function test_remoto_depende_de_la_url(): void
    {
        $this->assertTrue(EjecutorClaude::remoto());
        config(['agente.remoto.url' => null]);
        $this->assertFalse(EjecutorClaude::remoto());
    }

    public function test_el_alta_lleva_el_secreto_el_modelo_y_el_token_guardado(): void
    {
        TokenClaude::guardar('sk-ant-oat01-token-de-prueba-0123456789');
        $this->runner([['estado' => 'terminado', 'codigo' => 0, 'chunks' => [['out', 'ok']], 'siguiente' => 1]]);

        $r = (new EjecutorClaude)->ejecutar('Responde solo: ok', 'Read,Write', 120);

        [$alta] = $this->enviados('POST');
        $this->assertSame('http://agente:9000/ejecuciones', $alta->url());
        $this->assertSame('Bearer secreto-de-prueba-123', $alta->header('Authorization')[0]);
        $this->assertSame(['prompt' => 'Responde solo: ok', 'modelo' => 'claude-sonnet-5-5', 'herramientas' => 'Read,Write', 'timeout' => 120.0, 'token' => 'sk-ant-oat01-token-de-prueba-0123456789'], $alta->data());
        $this->assertTrue($r->exitoso());
        $this->assertSame('ok', $r->salida);
    }

    public function test_sin_token_ni_herramientas_ni_stream_esos_campos_no_se_envian(): void
    {
        $this->runner([['estado' => 'terminado', 'codigo' => 0, 'chunks' => [], 'siguiente' => 0]]);

        (new EjecutorClaude)->ejecutar('hola');

        $datos = $this->enviados('POST')[0]->data();
        $this->assertArrayNotHasKey('token', $datos);
        $this->assertArrayNotHasKey('herramientas', $datos);
        $this->assertArrayNotHasKey('stream', $datos);
    }

    public function test_el_polling_pide_desde_donde_se_quedo_y_junta_los_trozos(): void
    {
        $this->runner([
            ['estado' => 'corriendo', 'chunks' => [['out', 'uno '], ['err', 'aviso ']], 'siguiente' => 2],
            ['estado' => 'corriendo', 'chunks' => [['out', 'dos ']], 'siguiente' => 3],
            ['estado' => 'terminado', 'codigo' => 0, 'chunks' => [['out', 'tres']], 'siguiente' => 4],
        ]);
        $recibido = [];

        $r = (new EjecutorClaude)->ejecutar('hola', salida: function (string $tipo, string $texto) use (&$recibido) {
            $recibido[] = [$tipo, $texto];
        });

        $desdes = array_map(fn (Request $g): string => parse_url($g->url(), PHP_URL_QUERY), $this->enviados('GET'));
        $this->assertSame(['desde=0', 'desde=2', 'desde=3'], $desdes);
        $this->assertSame('uno dos tres', $r->salida);
        $this->assertSame('aviso ', $r->errores);
        $this->assertSame([['out', 'uno '], ['err', 'aviso '], ['out', 'dos '], ['out', 'tres']], $recibido);
    }

    public function test_el_id_de_la_ejecucion_se_comunica_al_dar_de_alta(): void
    {
        $this->runner([['estado' => 'terminado', 'codigo' => 0, 'chunks' => [], 'siguiente' => 0]]);
        $id = null;

        (new EjecutorClaude)->ejecutar('hola', alAlta: function (string $i) use (&$id) {
            $id = $i;
        });

        $this->assertSame('abc123', $id);
    }

    public function test_un_codigo_de_salida_distinto_de_cero_no_lanza(): void
    {
        $this->runner([['estado' => 'terminado', 'codigo' => 1, 'chunks' => [['err', 'boom']], 'siguiente' => 1]]);

        $r = (new EjecutorClaude)->ejecutar('hola');

        $this->assertFalse($r->exitoso());
        $this->assertSame(1, $r->codigo);
        $this->assertSame('boom', $r->texto());
    }

    public function test_con_stream_el_registro_es_legible_y_la_salida_final_es_el_resultado(): void
    {
        $init = json_encode(['type' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5'])."\n";
        $texto = json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hola']]]])."\n";
        $fin = json_encode(['type' => 'result', 'result' => 'Menú entregado', 'num_turns' => 2])."\n";
        $this->runner([
            ['estado' => 'corriendo', 'chunks' => [['out', $init.substr($texto, 0, 20)]], 'siguiente' => 1],
            ['estado' => 'terminado', 'codigo' => 0, 'chunks' => [['out', substr($texto, 20).$fin]], 'siguiente' => 2],
        ]);
        $log = '';

        $r = (new EjecutorClaude)->ejecutar('hola', salida: function (string $tipo, string $t) use (&$log) {
            $log .= $t;
        }, stream: true);

        $this->assertStringContainsString('▶ Sesión iniciada (claude-sonnet-5-5)', $log);
        $this->assertStringContainsString('💬 Hola', $log);
        $this->assertStringContainsString('✔ Terminado (2 turnos)', $log);
        $this->assertSame('Menú entregado', $r->salida);
        $this->assertTrue($this->enviados('POST')[0]->data()['stream']);
    }

    public function test_rechazo_del_alta_lanza_con_el_motivo(): void
    {
        $this->runner([], ['error' => 'no autorizado'], 401);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El agente no aceptó la ejecución (401): no autorizado');
        (new EjecutorClaude)->ejecutar('hola');
    }

    public function test_si_el_agente_se_reinicio_lanza_y_cancela_la_ejecucion(): void
    {
        $this->runner([['estado' => 'terminado', 'codigo' => -1, 'interrumpida' => true, 'chunks' => [], 'siguiente' => 0]]);

        try {
            (new EjecutorClaude)->ejecutar('hola');
            $this->fail('Debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('El agente se reinició', $e->getMessage());
        }

        $this->assertStringEndsWith('/ejecuciones/abc123', $this->enviados('DELETE')[0]->url());
    }

    public function test_tiempo_agotado_lanza(): void
    {
        $this->runner([['estado' => 'terminado', 'codigo' => -9, 'tiempo_agotado' => true, 'chunks' => [], 'siguiente' => 0]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('se pasó del tiempo (90 s)');
        (new EjecutorClaude)->ejecutar('hola', timeout: 90);
    }

    public function test_con_el_almacen_en_la_base_el_agente_recibe_la_entrada_y_sus_archivos_se_guardan(): void
    {
        config(['agente.almacen' => 'base']);
        AlmacenAgente::guardar('encargos/encargo-1.json', '{"encargo":1}');
        AlmacenAgente::guardar('workspace/ajeno.json', 'no se manda: no es de entrada');
        Http::fake(function (Request $r) {
            return match (true) {
                $r->method() === 'POST' => Http::response(['id' => 'abc123'], 202),
                str_ends_with($r->url(), '/archivos') => Http::response(['archivos' => [
                    'workspace/resultado-1.json' => base64_encode('{"ok":true}'),
                    'base/estado-actual.json' => base64_encode('{"fase":"terminada"}'),
                    '../fuera.json' => base64_encode('no'),
                    'encargos/encargo-1.json' => base64_encode('no se acepta: el agente no escribe encargos'),
                ]]),
                default => Http::response(['estado' => 'terminado', 'codigo' => 0, 'chunks' => [], 'siguiente' => 0]),
            };
        });

        (new EjecutorClaude)->ejecutar('hola', stream: true);

        $alta = $this->enviados('POST')[0]->data();
        $this->assertSame(['encargos/encargo-1.json' => base64_encode('{"encargo":1}')], $alta['archivos']);
        $this->assertSame('{"ok":true}', AlmacenAgente::leer('workspace/resultado-1.json'));
        $this->assertSame('{"fase":"terminada"}', AlmacenAgente::leer('base/estado-actual.json'));
        $this->assertNull(AlmacenAgente::leer('../fuera.json'));
        $this->assertSame('{"encargo":1}', AlmacenAgente::leer('encargos/encargo-1.json'));
    }

    public function test_sin_almacen_en_la_base_no_se_mandan_archivos(): void
    {
        $this->runner([['estado' => 'terminado', 'codigo' => 0, 'chunks' => [], 'siguiente' => 0]]);

        (new EjecutorClaude)->ejecutar('hola', stream: true);

        $this->assertArrayNotHasKey('archivos', $this->enviados('POST')[0]->data());
        $this->assertCount(0, array_filter($this->enviados('GET'), fn ($r) => str_ends_with($r->url(), '/archivos')));
    }

    public function test_si_falla_la_consulta_se_cancela_y_se_explica(): void
    {
        Http::fake(function (Request $r) {
            return match ($r->method()) {
                'POST' => Http::response(['id' => 'x9'], 202),
                'GET' => Http::response('', 500),
                default => Http::response(['ok' => true]),
            };
        });

        try {
            (new EjecutorClaude)->ejecutar('hola');
            $this->fail('Debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no respondió al consultar la ejecución (500)', $e->getMessage());
        }

        $this->assertStringEndsWith('/ejecuciones/x9', $this->enviados('DELETE')[0]->url());
    }

    public function test_cancelar_trata_el_404_como_exito(): void
    {
        $estado = 404;
        Http::fake(function () use (&$estado) {
            return Http::response('', $estado);
        });

        $this->assertTrue(EjecutorClaude::cancelar('abc'));
        $estado = 200;
        $this->assertTrue(EjecutorClaude::cancelar('abc'));
        $estado = 500;
        $this->assertFalse(EjecutorClaude::cancelar('abc'));
    }

    public function test_cancelar_sin_id_o_sin_agente_no_envia_nada(): void
    {
        Http::fake();

        $this->assertFalse(EjecutorClaude::cancelar(''));
        config(['agente.remoto.url' => null]);
        $this->assertFalse(EjecutorClaude::cancelar('abc'));

        Http::assertNothingSent();
    }

    public function test_en_local_se_usa_el_proceso_inyectado_y_se_informa_del_pid(): void
    {
        config(['agente.remoto.url' => null]);
        $proceso = new Process(['php', '-r', 'echo "hola"; fwrite(STDERR, "aviso"); exit(3);']);
        $pid = 'no-llamado';
        $recibido = '';

        $r = (new EjecutorClaude)->ejecutar('x', salida: function (string $tipo, string $t) use (&$recibido) {
            $recibido .= "{$tipo}:{$t};";
        }, alIniciar: function (?int $p) use (&$pid) {
            $pid = $p;
        }, proceso: $proceso);

        $this->assertIsInt($pid);
        $this->assertSame([3, 'hola', 'aviso'], [$r->codigo, $r->salida, $r->errores]);
        $this->assertStringContainsString('out:hola;', $recibido);
        $this->assertStringContainsString('err:aviso;', $recibido);
    }
}
