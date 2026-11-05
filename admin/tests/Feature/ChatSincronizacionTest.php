<?php

namespace Tests\Feature;

use App\Models\ChatMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiChat;
use Tests\TestCase;

class ChatSincronizacionTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararChat();
        Queue::fake();
    }

    /** @param  array<int, array<string, mixed>>  $abiertos */
    private function sync(int $despues, array $abiertos = [], ?string $titulo = null): array
    {
        return $this->api('POST', 'sincronizar', ['despues' => $despues, 'abiertos' => $abiertos, 'titulo' => $titulo])->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function abierto(ChatMensaje $m, int $texto = 0, int $razon = 0, ?string $estado = null, bool $fijado = false): array
    {
        return ['id' => $m->message_id, 'texto' => $texto, 'razonamiento' => $razon, 'estado' => $estado ?? $m->estado->value, 'fijado' => $fijado];
    }

    public function test_devuelve_lo_nuevo_en_orden_y_la_ultima_secuencia(): void
    {
        $this->enviarTexto('uno');
        $this->enviarTexto('dos');

        $todo = $this->sync(0);
        $this->assertSame(4, $todo['ultima_secuencia']);
        $this->assertSame([1, 2, 3, 4], array_column($todo['nuevos'], 'sequence'));
        $this->assertSame([], $todo['deltas']);

        $resto = $this->sync(2);
        $this->assertSame([3, 4], array_column($resto['nuevos'], 'sequence'));
        $this->assertSame([], $this->sync(4)['nuevos']);
    }

    public function test_cuerpo_no_valido(): void
    {
        $this->api('POST', 'sincronizar', ['despues' => 'x'])->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->api('POST', 'sincronizar', ['despues' => 0, 'abiertos' => [['sin' => 'id']]])->assertStatus(422);
        $this->api('POST', 'sincronizar', ['despues' => 0, 'abiertos' => 'no'])->assertStatus(422);
    }

    public function test_deltas_de_texto_y_razonamiento_y_estado_final_despues(): void
    {
        $this->enviarTexto('hola');
        $respuesta = $this->mensajePorSecuencia(2);
        $respuesta->iniciar();
        $respuesta->anadirTexto('Hola, ');
        $respuesta->anadirRazonamiento('pienso');

        $a = $this->sync(2, [$this->abierto($respuesta, 0, 0, 'PENDING')]);
        $this->assertSame([], $a['deltas'], 'un mensaje que el traductor tiene como PENDING solo recibe el paso a IN_PROGRESS');
        $this->assertSame('IN_PROGRESS', $a['estados'][0]['estado']);

        $b = $this->sync(2, [$this->abierto($respuesta, 0, 0, 'IN_PROGRESS')]);
        $this->assertSame([['id' => $respuesta->message_id, 'texto_anadido' => 'Hola, ', 'razonamiento_anadido' => 'pienso']], $b['deltas']);
        $this->assertSame([], $b['estados']);

        $respuesta->anadirTexto('mundo');
        $respuesta->finalizar(['Gracias', 'Otra', 'Tres', 'Cuatro']);
        $c = $this->sync(2, [$this->abierto($respuesta, 6, 6, 'IN_PROGRESS')]);
        $this->assertSame('mundo', $c['deltas'][0]['texto_anadido']);
        $this->assertSame('', $c['deltas'][0]['razonamiento_anadido']);
        $this->assertSame('COMPLETED', $c['estados'][0]['estado']);
        $this->assertSame(['Gracias', 'Otra', 'Tres'], $c['estados'][0]['sugerencias']);
        $this->assertNull($c['estados'][0]['error']);

        $this->assertSame([], $this->sync(2, [$this->abierto($respuesta, 11, 6, 'COMPLETED')])['estados']);
    }

    public function test_el_estado_final_no_sale_mientras_queden_bytes_y_los_deltas_no_parten_caracteres(): void
    {
        config(['chat.max_delta_bytes' => 5]);
        $texto = 'añ😀b😀ñá';
        $this->enviarTexto('hola');
        $respuesta = $this->mensajePorSecuencia(2);
        $respuesta->anadirTexto($texto);
        $respuesta->finalizar();

        $recibido = '';
        $vistas = 0;
        $estadoFinal = null;
        for ($i = 0; $i < 20 && $estadoFinal === null; $i++) {
            $r = $this->sync(2, [$this->abierto($respuesta, strlen($recibido), 0, 'IN_PROGRESS')]);
            foreach ($r['deltas'] as $delta) {
                $this->assertTrue(mb_check_encoding($delta['texto_anadido'], 'UTF-8'));
                $this->assertLessThanOrEqual(5, strlen($delta['texto_anadido']));
                $this->assertNotSame('', $delta['texto_anadido']);
                $recibido .= $delta['texto_anadido'];
            }
            $vistas++;
            $estadoFinal = $r['estados'][0]['estado'] ?? null;
            if ($estadoFinal !== null) {
                $this->assertSame($texto, $recibido, 'el estado final llega con todos los deltas ya enviados');
            }
        }

        $this->assertSame('COMPLETED', $estadoFinal);
        $this->assertSame($texto, $recibido);
        $this->assertGreaterThan(3, $vistas);
    }

    public function test_un_delta_de_64_kib_exactos_con_multibyte_en_el_limite(): void
    {
        $this->enviarTexto('hola');
        $respuesta = $this->mensajePorSecuencia(2);
        // 65 535 bytes de «a» y luego «ñ» (2 bytes): el corte en 65 536 partiria la «ñ».
        $texto = str_repeat('a', 65535).'ñ'.str_repeat('b', 10);
        $respuesta->anadirTexto($texto);

        $r = $this->sync(2, [$this->abierto($respuesta, 0, 0, 'IN_PROGRESS')]);
        $this->assertSame(65535, strlen($r['deltas'][0]['texto_anadido']));
        $this->assertTrue(mb_check_encoding($r['deltas'][0]['texto_anadido'], 'UTF-8'));

        $r2 = $this->sync(2, [$this->abierto($respuesta, 65535, 0, 'IN_PROGRESS')]);
        $this->assertSame('ñ'.str_repeat('b', 10), $r2['deltas'][0]['texto_anadido']);
    }

    public function test_cambios_de_fijado_y_mensajes_abiertos_nuevos_no_se_repiten(): void
    {
        $this->enviarTexto('uno');
        $respuesta = $this->mensajePorSecuencia(2);
        $respuesta->anadirTexto('texto');
        $this->api('POST', 'mensajes/'.$respuesta->message_id.'/fijar', ['fijado' => true])->assertOk();

        $r = $this->sync(2, [$this->abierto($respuesta, 5, 0, 'IN_PROGRESS', false)]);
        $this->assertTrue($r['fijados'][0]['fijado']);
        $this->assertSame($respuesta->message_id, $r['fijados'][0]['id']);

        // Si el traductor aun no lo tenia (sequence > despues), viaja entero en `nuevos` y no en `deltas`.
        $r = $this->sync(1, [$this->abierto($respuesta, 0, 0, 'PENDING')]);
        $this->assertCount(1, $r['nuevos']);
        $this->assertSame([], $r['deltas']);
        $this->assertSame([], $r['estados']);

        $this->assertSame([], $this->sync(2, [['id' => 'desconocido', 'texto' => 0, 'razonamiento' => 0, 'estado' => 'IN_PROGRESS', 'fijado' => false]])['deltas']);
    }

    public function test_fallo_con_error_y_cancelacion(): void
    {
        $this->enviarTexto('uno');
        $this->enviarTexto('dos');
        $a = $this->mensajePorSecuencia(2);
        $b = $this->mensajePorSecuencia(4);
        $a->anadirTexto('xx');
        $a->fallar('Se acabó el tiempo');
        $b->cancelar();

        $r = $this->sync(4, [$this->abierto($a, 2, 0, 'IN_PROGRESS'), $this->abierto($b, 0, 0, 'IN_PROGRESS')]);
        $porId = array_column($r['estados'], null, 'id');
        $this->assertSame('FAILED', $porId[$a->message_id]['estado']);
        $this->assertSame('Se acabó el tiempo', $porId[$a->message_id]['error']);
        $this->assertSame([], $porId[$a->message_id]['sugerencias']);
        $this->assertSame('CANCELLED', $porId[$b->message_id]['estado']);
    }

    public function test_el_titulo_se_notifica_una_vez(): void
    {
        $this->assertSame(['cambio' => false, 'valor' => null], Arr::only($this->sync(0)['titulo'], ['cambio', 'valor']));

        $this->enviarTexto('Menú para esta semana por favor y gracias');

        $titulo = $this->sync(0, [], null)['titulo'];
        $this->assertSame(['cambio' => true, 'valor' => 'Menú para esta semana por favor…'], Arr::only($titulo, ['cambio', 'valor']));
        // §6.10: la hora del cambio es una sola para todos los streams (prueba 86).
        $this->assertGreaterThan(1_700_000_000_000, $titulo['actualizado_ms']);
        $this->assertSame($titulo['actualizado_ms'], $this->sync(0)['titulo']['actualizado_ms']);
        $this->assertSame(
            ['cambio' => false, 'valor' => 'Menú para esta semana por favor…'],
            Arr::only($this->sync(2, [], 'Menú para esta semana por favor…')['titulo'], ['cambio', 'valor']),
        );
    }
}
