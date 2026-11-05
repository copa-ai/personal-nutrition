<?php

namespace Tests\Feature;

use App\Enums\EstadoChat;
use App\Models\ChatMensaje;
use App\Services\Chat\Archivos;
use App\Services\Chat\Conversacion;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PDOException;
use RuntimeException;
use Tests\Support\ApiChat;
use Tests\TestCase;

/** Reglas de chat.md rev. 6 que quedaban sin cubrir: §5.7 (sugerencias y corrector), §5.2 (toda respuesta termina) y §6.4 (storage_full). */
class ChatConformidadTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararChat();
        Queue::fake();
    }

    public function test_las_sugerencias_son_de_una_linea_de_hasta_64_caracteres_y_como_mucho_tres(): void
    {
        $largo = str_repeat('é', 80);
        $limpias = Conversacion::limpiarSugerencias(["Sí\nclaro", "  \t ", 'Con'."\x00".'nul', $largo, 'cuarta', 5, "\x7Fx"]);

        $this->assertSame(['Sí claro', 'Con nul', str_repeat('é', 64)], $limpias);
        foreach ($limpias as $sugerencia) {
            $this->assertSame(1, preg_match('/^[^\x00-\x1F\x7F]{1,64}$/u', $sugerencia));
        }
    }

    public function test_finalizar_y_publicar_partes_guardan_sugerencias_validas(): void
    {
        $this->enviarTexto('hola')->assertOk();
        $respuesta = $this->mensajePorSecuencia(2);
        $respuesta->anadirTexto('x');
        $respuesta->finalizar(["Uno\ndos", str_repeat('a', 100)]);

        $this->assertSame(['Uno dos', str_repeat('a', 64)], $respuesta->fresh()->sugerencias);
        $nuevo = $respuesta->anadirImagen($this->subirPng());
        $this->assertSame([], $nuevo->sugerencias);
        $publicado = app(Conversacion::class)->publicarPartes($respuesta, [['tipo' => 'texto', 'texto' => 'a']], ["x\ty", 'b', 'c', 'd']);
        $this->assertSame(['x y', 'b', 'c'], $publicado->sugerencias);
    }

    private function subirPng(): string
    {
        $bytes = $this->bytesPng();
        $this->subir($bytes)->assertOk();

        return hash('sha3-256', $bytes);
    }

    public function test_el_corrector_se_guarda_dentro_de_los_limites_y_nunca_hace_fallar_el_envio(): void
    {
        $correcciones = [];
        for ($i = 0; $i < 40; $i++) {
            $correcciones[] = ['original' => "teh$i", 'replacement' => "the$i"];
        }
        $correcciones[] = ['original' => '', 'replacement' => 'x'];
        $correcciones[] = ['original' => str_repeat('a', 65), 'replacement' => 'x'];
        $correcciones[] = 'no es una correccion';

        $respuesta = $this->enviarTexto('the cat', ['corrector' => ['enabled' => true, 'corrections' => $correcciones]])->assertOk();

        $corrector = $respuesta->json('corrector');
        $this->assertTrue($corrector['enabled']);
        $this->assertCount(32, $corrector['corrections']);
        $this->assertSame(['original' => 'teh0', 'replacement' => 'the0'], $corrector['corrections'][0]);
        $this->assertSame('the cat', $respuesta->json('content.0.texto'));
        $this->api('GET', 'mensajes/'.$respuesta->json('message_id'))->assertOk()->assertJsonPath('corrector.corrections.31.original', 'teh31');

        $this->enviarTexto('otra', ['corrector' => 'basura'])->assertOk()->assertJsonPath('corrector', null);
        $this->enviarTexto('sin')->assertOk()->assertJsonPath('corrector', null);
        $this->enviarTexto('vacio', ['corrector' => ['enabled' => false]])->assertOk()
            ->assertJsonPath('corrector', ['enabled' => false, 'corrections' => []]);
    }

    public function test_una_respuesta_abierta_desde_hace_horas_se_cierra_y_no_deja_el_chat_en_busy(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->enviarTexto("m$i")->assertOk();
        }
        $this->enviarTexto('uno mas')->assertStatus(503)->assertJsonPath('codigo', 'busy');

        // Una respuesta reciente no se toca; las que llevan mas de 60 minutos abiertas (proceso muerto) pasan a FAILED.
        ChatMensaje::query()->where('from_service', true)->update(['created_ms' => Conversacion::ahoraMs() - 61 * 60_000]);
        $this->mensajePorSecuencia(16)->update(['created_ms' => Conversacion::ahoraMs()]);

        $this->enviarTexto('ahora si')->assertOk();

        $cerrada = $this->mensajePorSecuencia(2);
        $this->assertSame(EstadoChat::Fallido, $cerrada->estado);
        $this->assertNotSame('', (string) $cerrada->error);
        $this->assertLessThanOrEqual(1024, strlen((string) $cerrada->error));
        $this->assertFalse($cerrada->anadirTexto('tarde'));   // el motor que siguiera vivo ya no escribe
        $this->assertSame(EstadoChat::Pendiente, $this->mensajePorSecuencia(16)->estado);
        $this->assertSame(EstadoChat::Pendiente, $this->mensajePorSecuencia(18)->estado);
    }

    public function test_un_fallo_de_falta_de_recursos_de_postgres_es_storage_full(): void
    {
        $disco = new class('disco lleno') extends PDOException
        {
            protected $code = '53100';
        };
        $this->assertTrue(Archivos::faltaSitio(new QueryException('pgsql', 'insert', [], $disco)));

        $otro = new class('clave duplicada') extends PDOException
        {
            protected $code = '23505';
        };
        $this->assertFalse(Archivos::faltaSitio(new QueryException('pgsql', 'insert', [], $otro)));
    }

    public function test_si_la_cola_no_acepta_el_trabajo_la_respuesta_termina_en_failed_y_el_envio_sigue_siendo_correcto(): void
    {
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('cola caida')));

        $this->enviarTexto('hola')->assertOk()->assertJsonPath('sequence', 1);

        $respuesta = $this->mensajePorSecuencia(2);
        $this->assertSame(EstadoChat::Fallido, $respuesta->estado);
        $this->assertNotSame('', (string) $respuesta->error);
        $this->assertSame(EstadoChat::Completado, $this->mensajePorSecuencia(1)->estado);
    }
}
