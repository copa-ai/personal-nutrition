<?php

namespace Tests\Feature;

use App\Enums\EstadoChat;
use App\Models\AgenteArchivo;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\RegistroComida;
use App\Services\Chat\Claude\AccionesChat;
use App\Services\Chat\Claude\InstantaneaDatos;
use App\Services\Chat\Claude\RespondedorClaude;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PreparaChatClaude;
use Tests\TestCase;

class ChatClaudeRespondedorTest extends TestCase
{
    use PreparaChatClaude, RefreshDatabase;

    private const TECLADO = "```keyboard\n<div><button data-say=\"Para Marta\" data-send>Marta</button></div>\n```";

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararClaude();
    }

    protected function tearDown(): void
    {
        $this->limpiarClaude();
        parent::tearDown();
    }

    private function responder(string $pregunta = '¿Qué ceno hoy?', array $partes = [], ?RespondedorClaude $motor = null): ChatMensaje
    {
        [$p, $r] = $this->preguntar($pregunta, $partes);
        ($motor ?? app(RespondedorClaude::class))->responder($p, $r);

        return $r->fresh();
    }

    private function salidaNormal(): string
    {
        return $this->lineas(
            ['type' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5'],
            $this->pensar('La persona pregunta por la cena.'),
            $this->decir('Voy a leer la instantánea.', conHerramienta: true),
            ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'datos']]]],
            $this->decir("Para cenar hoy, Marta: tortilla de calabacín.\n\n".self::TECLADO),
            $this->resultado('Para cenar hoy, Marta: tortilla de calabacín.'),
        );
    }

    public function test_flujo_feliz_razonamiento_texto_formulario_y_acciones(): void
    {
        TokenClaude::guardar('sk-ant-oat01-token-de-prueba-0123456789');
        [$p, $r] = $this->preguntar('¿Qué ceno hoy?');
        $id = $r->id;
        $this->runner([$this->salidaNormal()], archivos: [AccionesChat::relativa($id) => (string) json_encode(['acciones' => [
            ['tipo' => 'registrar_comida', 'perfil' => $this->marta->id, 'fecha' => '2026-11-05', 'momento' => 'comida', 'descripcion' => 'Lentejas'],
            ['tipo' => 'registrar_comida', 'perfil' => 9999, 'fecha' => '2026-11-05', 'momento' => 'comida', 'descripcion' => 'Fantasma'],
        ]])]);
        config(['agente.almacen' => 'base']);

        app(RespondedorClaude::class)->responder($p, $r);
        $respuesta = $r->fresh();

        $this->assertSame(EstadoChat::Completado, $respuesta->estado);
        $this->assertStringContainsString('La persona pregunta por la cena.', $respuesta->razonamiento);
        $this->assertStringContainsString('Voy a leer la instantánea.', $respuesta->razonamiento);
        $texto = $respuesta->texto();
        $this->assertStringStartsWith('Para cenar hoy, Marta: tortilla de calabacín.', $texto);
        $this->assertStringContainsString('```keyboard', $texto);
        $this->assertStringNotContainsString('Voy a leer', $texto, 'lo que dice mientras usa herramientas es razonamiento, no respuesta');
        $this->assertStringNotContainsString('La persona pregunta', $texto);
        $this->assertStringContainsString('Cambios guardados', $texto);
        $this->assertStringContainsString('No se han aplicado', $texto);
        $this->assertSame(['Lentejas'], RegistroComida::query()->pluck('descripcion')->all());
        $this->assertSame(1, Auditoria::query()->where('evento', 'chat.accion_aplicada')->count());
        $this->assertSame(1, Auditoria::query()->where('evento', 'chat.accion_rechazada')->count());
        $this->assertEmpty($respuesta->metadata);

        [$alta] = $this->enviados('POST');
        $this->assertSame(RespondedorClaude::prompt($id), $alta['prompt']);
        $this->assertStringStartsWith("Turno de chat {$id}.", $alta['prompt']);
        $this->assertTrue($alta['stream']);
        $this->assertSame('sk-ant-oat01-token-de-prueba-0123456789', $alta['token']);
        $this->assertSame('Read,Glob,Grep,Write', $alta['herramientas']);
        $this->assertArrayHasKey("encargos/chat-{$id}/LEEME.json", $alta['archivos'], 'el runner recibe la instantánea como los encargos de menús');
        $this->assertArrayHasKey("encargos/chat-{$id}/perfiles.json", $alta['archivos']);
        $this->assertStringNotContainsString('sk-ant', implode('', array_map('base64_decode', $alta['archivos'])), 'la instantánea no lleva el token');

        // Limpieza: ni en disco ni en la base queda nada del turno.
        $this->assertDirectoryDoesNotExist(InstantaneaDatos::ruta($id));
        $this->assertFileDoesNotExist($this->carpeta.'/'.AccionesChat::relativa($id));
        $this->assertSame(0, AgenteArchivo::query()->count());
    }

    public function test_el_texto_llega_en_tiempo_real_y_la_respuesta_queda_en_marcha_hasta_el_final(): void
    {
        $vistos = [];
        [$p, $r] = $this->preguntar('hola');
        $this->runner([
            $this->lineas($this->pensar('Pienso.')),
            $this->lineas($this->decir('Primera parte.')),
            $this->lineas($this->decir('Segunda parte.'), $this->resultado()),
        ], function (int $i) use ($r, &$vistos): void {
            $f = ChatMensaje::find($r->id);
            $vistos[$i] = [$f->estado->value, $f->razonamiento, $f->texto()];
        });

        app(RespondedorClaude::class)->responder($p, $r);

        $this->assertSame(['PENDING', '', ''], $vistos[0], 'antes de la primera salida no hay nada');
        $this->assertSame(['IN_PROGRESS', 'Pienso.', ''], [$vistos[1][0], trim($vistos[1][1]), $vistos[1][2]], 'el razonamiento llega antes que el texto');
        $this->assertSame('Primera parte.', $vistos[2][2], 'el texto llega mientras el agente sigue');
        $this->assertSame('IN_PROGRESS', $vistos[2][0]);
        $this->assertSame("Primera parte.\n\nSegunda parte.", $r->fresh()->texto());
        $this->assertSame(EstadoChat::Completado, $r->fresh()->estado);
    }

    public function test_sin_texto_por_el_camino_se_usa_la_respuesta_final(): void
    {
        $this->runner([$this->lineas($this->pensar('Hmm.'), $this->resultado('Respuesta del resultado.'))]);

        $this->assertSame('Respuesta del resultado.', $this->responder()->texto());
    }

    public function test_el_saneador_de_marcado_se_aplica(): void
    {
        $motor = new class(app(InstantaneaDatos::class), app(AccionesChat::class)) extends RespondedorClaude
        {
            protected function sanear(string $texto): string
            {
                return str_replace('script', 'SANEADO', $texto);
            }
        };
        $this->runner([$this->lineas($this->decir('hola script'), $this->decir('adiós script'), $this->resultado())]);

        $this->assertSame("hola SANEADO\n\nadiós SANEADO", $this->responder(motor: $motor)->texto());
    }

    public function test_un_widget_valido_pasa_por_el_validador_de_marcado_sin_cambios(): void
    {
        $this->runner([$this->lineas($this->decir("```html\n<div>x</div>\n```"), $this->resultado())]);

        $respuesta = $this->responder();

        $this->assertSame("```html\n<div>x</div>\n```", $respuesta->texto());
    }

    public function test_agente_ocupado_espera_en_pending_con_progreso_y_reintenta(): void
    {
        [$p, $r] = $this->preguntar('hola');
        $estadosEnLaEspera = [];
        $esperas = 0;
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        $altaN = 0;
        Http::fake(function (Request $req) use ($r, &$estadosEnLaEspera, &$altaN, &$esperas) {
            if ($req->method() === 'POST') {
                if ($altaN++ < 2) {
                    $f = ChatMensaje::find($r->id);
                    $estadosEnLaEspera[] = [$f->estado->value, $f->metadata['progreso'] ?? null, $f->razonamiento, $f->texto()];
                    $esperas++;

                    return Http::response(['error' => 'demasiadas ejecuciones a la vez'], 429);
                }

                return Http::response(['id' => 'run1'], 202);
            }
            if ($req->method() === 'GET') {
                return Http::response(['estado' => 'terminado', 'codigo' => 0, 'siguiente' => 1, 'chunks' => [['out', $this->salidaNormal()]]]);
            }

            return Http::response(['ok' => true]);
        });

        app(RespondedorClaude::class)->responder($p, $r);

        $this->assertSame(2, $esperas);
        $this->assertSame('PENDING', $estadosEnLaEspera[1][0], 'mientras espera sigue PENDING');
        $this->assertStringContainsString('Esperando', (string) $estadosEnLaEspera[1][1]);
        $this->assertSame(['', ''], [$estadosEnLaEspera[1][2], $estadosEnLaEspera[1][3]]);
        $final = $r->fresh();
        $this->assertSame(EstadoChat::Completado, $final->estado);
        $this->assertNull($final->metadata, 'el aviso de espera se quita al empezar a responder');
        $this->assertStringContainsString('tortilla', $final->texto());
        $this->assertCount(3, $this->enviados('POST'));
    }

    public function test_agente_ocupado_demasiado_tiempo_falla_con_un_mensaje_claro_sin_bloquear(): void
    {
        config(['chat_claude.espera_agente_max_s' => 0]);
        $this->runner([$this->salidaNormal()], altas: [429]);

        $respuesta = $this->responder();

        $this->assertSame(EstadoChat::Fallido, $respuesta->estado);
        $this->assertStringContainsString('ocupado', (string) $respuesta->error);
        $this->assertCount(1, $this->enviados('POST'), 'no reintenta indefinidamente');
        $this->assertDirectoryDoesNotExist(InstantaneaDatos::ruta($respuesta->id));
    }

    public function test_limite_de_uso_no_es_un_fallo_y_se_dice_cuando_se_renueva(): void
    {
        $this->runner([$this->lineas(['type' => 'system', 'subtype' => 'init'])."You've hit your session limit · resets 3:20pm (UTC)\n"], final: ['codigo' => 1]);

        $respuesta = $this->responder();

        $this->assertSame(EstadoChat::Completado, $respuesta->estado);
        $this->assertNull($respuesta->error);
        $this->assertStringContainsString('límite de uso', $respuesta->texto());
        $this->assertMatchesRegularExpression('/\d\d:\d\d/', $respuesta->texto());
        $this->assertSame(0, RegistroComida::query()->count());
    }

    public function test_cancelar_mata_la_ejecucion_y_no_aplica_acciones(): void
    {
        [$p, $r] = $this->preguntar('registra que he comido lentejas');
        $this->runner([
            $this->lineas($this->pensar('Pienso.')),
            $this->lineas($this->decir('Texto que llega tarde.')),
            $this->lineas($this->decir('Más texto.'), $this->resultado()),
        ], function (int $i) use ($r): void {
            $i === 1 && $this->api('POST', 'mensajes/'.$r->message_id.'/cancelar')->assertOk();
        }, archivos: [AccionesChat::relativa($r->id) => (string) json_encode(['acciones' => [
            ['tipo' => 'registrar_comida', 'perfil' => $this->marta->id, 'fecha' => '2026-11-05', 'momento' => 'comida', 'descripcion' => 'Lentejas'],
        ]])]);

        app(RespondedorClaude::class)->responder($p, $r);

        $final = $r->fresh();
        $this->assertSame(EstadoChat::Cancelado, $final->estado);
        $this->assertSame('', $final->texto(), 'tras cancelar no se escribe nada más');
        $this->assertCount(1, $this->enviados('DELETE'), 'se mata la ejecución en el runner');
        $this->assertStringEndsWith('/ejecuciones/run1', $this->enviados('DELETE')[0]->url());
        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertDirectoryDoesNotExist(InstantaneaDatos::ruta($r->id));
    }

    public function test_cancelar_se_nota_aunque_el_agente_no_diga_nada(): void
    {
        [$p, $r] = $this->preguntar('hola');
        $this->runner(['', '', ''], function (int $i) use ($r): void {
            $i === 0 && $this->api('POST', 'mensajes/'.$r->message_id.'/cancelar')->assertOk();   // el agente lee en silencio: no hay salida que despierte la comprobacion
        });

        app(RespondedorClaude::class)->responder($p, $r);

        $this->assertSame(EstadoChat::Cancelado, $r->fresh()->estado);
        $this->assertCount(1, $this->enviados('DELETE'), 'se mata la ejecución en el runner');
    }

    public function test_el_texto_de_claude_se_sanea_con_el_validador_de_marcado(): void
    {
        $this->runner([$this->lineas(
            $this->decir("Elige:\n\n```keyboard\n<div><button data-say=\"hola\" data-send onclick=\"robar()\">Hola</button><script>robar()</script></div>\n```"),
            $this->resultado(),
        )]);

        $texto = $this->responder()->texto();

        $this->assertStringContainsString('```keyboard', $texto);
        $this->assertStringContainsString('data-say="hola"', $texto);
        $this->assertStringNotContainsString('onclick', $texto);
        $this->assertStringNotContainsString('<script', $texto);
    }

    public function test_un_proceso_que_falla_deja_failed_con_un_error_corto(): void
    {
        $this->runner([$this->lineas($this->pensar('Empiezo.'))."Traceback (most recent call last):\n  File secreto.py\nboom\n"], final: ['codigo' => 1]);

        $respuesta = $this->responder();

        $this->assertSame(EstadoChat::Fallido, $respuesta->estado);
        $this->assertLessThan(200, strlen((string) $respuesta->error));
        $this->assertStringNotContainsString('Traceback', (string) $respuesta->error);
        $this->assertStringNotContainsString('secreto.py', (string) $respuesta->error);
        $this->assertDirectoryDoesNotExist(InstantaneaDatos::ruta($respuesta->id));
    }

    public function test_pasarse_del_tiempo_deja_failed(): void
    {
        $this->runner([$this->lineas($this->pensar('Empiezo.'))], final: ['tiempo_agotado' => true]);

        $respuesta = $this->responder();

        $this->assertSame(EstadoChat::Fallido, $respuesta->estado);
        $this->assertLessThan(200, strlen((string) $respuesta->error));
    }

    public function test_si_el_agente_no_responde_y_no_habia_nada_escrito_se_relanza_el_error_para_el_bot(): void
    {
        $this->runner([''], altas: [500]);
        [$p, $r] = $this->preguntar('hola');

        $this->expectException(\RuntimeException::class);
        try {
            app(RespondedorClaude::class)->responder($p, $r);
        } finally {
            $this->assertSame(EstadoChat::Pendiente, $r->fresh()->estado, 'sigue abierta para que RespondedorMixto la conteste con el bot');
            $this->assertDirectoryDoesNotExist(InstantaneaDatos::ruta($r->id));
        }
    }

    public function test_sin_ningun_texto_ni_acciones_falla_con_un_mensaje_claro(): void
    {
        $this->runner([$this->lineas(['type' => 'system', 'subtype' => 'init'], $this->resultado(''))]);

        $respuesta = $this->responder();

        $this->assertSame(EstadoChat::Fallido, $respuesta->estado);
        $this->assertStringContainsString('ninguna respuesta', (string) $respuesta->error);
    }

    public function test_acciones_sin_almacen_se_leen_del_disco_y_un_archivo_roto_se_explica(): void
    {
        [$p, $r] = $this->preguntar('hola');
        $archivo = $this->carpeta.'/'.AccionesChat::relativa($r->id);
        $this->runner([$this->salidaNormal()], function (int $i) use ($archivo): void {
            file_put_contents($archivo, '{esto no es json');   // lo que deja el agente local
        });

        app(RespondedorClaude::class)->responder($p, $r);
        $respuesta = $r->fresh();

        $this->assertSame(EstadoChat::Completado, $respuesta->estado);
        $this->assertStringContainsString('formato esperado', $respuesta->texto());
        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertFileDoesNotExist($archivo);
    }

    public function test_con_el_respondedor_mixto_y_un_token_responde_claude_y_sin_el_runner_responde_el_bot(): void
    {
        TokenClaude::guardar('sk-ant-oat01-token-de-prueba-0123456789');
        $this->runner([$this->salidaNormal()]);
        config(['queue.default' => 'sync']);

        $this->api('POST', 'mensajes', ['content' => [['tipo' => 'texto', 'texto' => '¿Qué ceno?']]])->assertOk();

        $primera = $this->mensajePorSecuencia(2);
        $this->assertSame(EstadoChat::Completado, $primera->estado);
        $this->assertStringContainsString('tortilla', $primera->texto());

        $this->runner([''], altas: [500]);
        $this->api('POST', 'mensajes', ['content' => [['tipo' => 'texto', 'texto' => 'otra']]])->assertOk();

        $segunda = $this->mensajePorSecuencia(4);
        $this->assertSame(EstadoChat::Completado, $segunda->estado, 'si el agente no responde y no hay nada escrito, contesta el bot/eco');
        $this->assertStringNotContainsString('tortilla', $segunda->texto());
    }

    /**
     * tests/Fixtures/chat-claude-falso*.{ndjson,json} son la salida REAL de `docker/pruebas/claude-falso.py` (modo invalida) para una pregunta
     * «registra que he comido lentejas» con la foto de bytesPng(): se comprueba que Laravel entiende lo que emite el Claude falso.
     */
    public function test_con_la_salida_real_del_claude_falso(): void
    {
        $acciones = json_decode((string) file_get_contents(base_path('tests/Fixtures/chat-claude-falso-acciones.json')), true);
        $acciones['acciones'][0]['perfil'] = $this->marta->id;   // en el fixture era el primer perfil (id 1)
        $this->columnaFoto();
        [$p, $r] = $this->preguntar('registra que he comido lentejas', [$this->foto($this->bytesPng())]);
        $this->runner([(string) file_get_contents(base_path('tests/Fixtures/chat-claude-falso.ndjson'))], archivos: [AccionesChat::relativa($r->id) => (string) json_encode($acciones)]);
        config(['agente.almacen' => 'base']);

        app(RespondedorClaude::class)->responder($p, $r);

        $final = $r->fresh();
        $this->assertSame(EstadoChat::Completado, $final->estado);
        $this->assertStringContainsString('La persona pregunta por la comida', $final->razonamiento);
        $this->assertStringContainsString('Leo la instantánea del hogar.', $final->razonamiento);
        $this->assertStringStartsWith('En casa hay', $final->texto());
        $this->assertStringContainsString('```keyboard', $final->texto());
        $this->assertStringContainsString('Cambios guardados', $final->texto());
        $this->assertStringContainsString('No se han aplicado', $final->texto());
        $registro = RegistroComida::query()->firstOrFail();
        $this->assertSame('Lentejas con arroz (según la conversación)', $registro->descripcion);
        $this->assertSame($this->marta->id, $registro->perfil_id);
        $this->assertSame(hash('sha3-256', $this->bytesPng()), $registro->foto_hash);
    }

    private function columnaFoto(): void
    {
        Schema::hasColumn('registros_comida', 'foto_hash') || Schema::table('registros_comida', fn ($t) => $t->string('foto_hash', 64)->nullable());
    }
}
