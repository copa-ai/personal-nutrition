<?php

namespace Tests\Feature;

use App\Enums\EstadoChat;
use App\Jobs\ResponderChat;
use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiChat;
use Tests\TestCase;

class ChatEnvioTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararChat();
    }

    public function test_sin_secreto_o_con_secreto_malo_responde_401(): void
    {
        $this->getJson('/interno/chat/salud')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer otro'])->getJson('/interno/chat/salud')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Basic '.self::SECRETO_CHAT])->getJson('/interno/chat/capacidades')->assertStatus(401);

        config(['chat.secreto' => '']);
        $this->withHeaders(['Authorization' => 'Bearer '])->getJson('/interno/chat/salud')->assertStatus(401);
        $this->getJson('/interno/chat/salud')->assertStatus(401);
    }

    public function test_con_el_chat_apagado_todo_da_503_deshabilitado_menos_salud(): void
    {
        $this->api('GET', 'salud')->assertOk()->assertExactJson(['habilitado' => true]);

        Ajuste::fijar(Ajuste::CHAT_HABILITADO, false);

        $this->api('GET', 'salud')->assertOk()->assertExactJson(['habilitado' => false]);
        foreach ([['GET', 'capacidades'], ['GET', 'conversacion'], ['GET', 'mensajes'], ['POST', 'sincronizar'], ['POST', 'mensajes']] as [$metodo, $ruta]) {
            $this->api($metodo, $ruta)->assertStatus(503)->assertJsonPath('codigo', 'deshabilitado');
        }
        $this->assertSame(0, ChatMensaje::count());
    }

    public function test_capacidades_y_conversacion_vacia(): void
    {
        $this->api('GET', 'capacidades')->assertOk()->assertExactJson([
            'tipos_mime' => ['image/jpeg', 'image/png'], 'max_archivo_bytes' => 4194304, 'max_archivos' => 4,
            'max_texto_bytes' => 16384, 'soporta_ramas' => false, 'soporta_cancelar' => true,
        ]);

        $conversacion = $this->api('GET', 'conversacion')->assertOk()->json();
        $this->assertNull($conversacion['titulo']);
        $this->assertSame(0, $conversacion['ultima_secuencia']);
        $this->assertGreaterThan(1_700_000_000_000, $conversacion['creada_ms']);
    }

    public function test_enviar_crea_el_mensaje_y_su_respuesta_y_fija_el_titulo(): void
    {
        $respuesta = $this->enviarTexto("  Hola   qué debería comer hoy mi hijo de cinco años\ncon alergia", ['idempotency_key' => 'k-1', 'corrector' => ['enabled' => true, 'corrections' => []]])
            ->assertOk();

        $respuesta->assertJsonPath('sequence', 1)->assertJsonPath('status', 'COMPLETED')->assertJsonPath('from_service', false)
            ->assertJsonPath('idempotency_key', 'k-1')->assertJsonPath('conversation_id', null)->assertJsonPath('reply_to', null)
            ->assertJsonPath('content.0.texto', "  Hola   qué debería comer hoy mi hijo de cinco años\ncon alergia")
            ->assertJsonPath('corrector.enabled', true);
        $this->assertIsString($respuesta->json('message_id'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $respuesta->json('message_id'));

        $mensajes = $this->api('GET', 'mensajes')->assertOk()->json('mensajes');
        $this->assertCount(2, $mensajes);
        $this->assertSame([1, 2], array_column($mensajes, 'sequence'));
        $this->assertTrue($mensajes[1]['from_service']);
        $this->assertSame($mensajes[0]['message_id'], $mensajes[1]['reply_to']);
        $this->assertSame('COMPLETED', $mensajes[1]['status']);
        $this->assertSame('Eco: '.$mensajes[0]['content'][0]['texto'], $mensajes[1]['content'][0]['texto']);

        $this->api('GET', 'conversacion')->assertJsonPath('titulo', 'Hola qué debería comer hoy mi…')->assertJsonPath('ultima_secuencia', 2);

        $this->enviarTexto('Otra cosa')->assertOk();
        $this->api('GET', 'conversacion')->assertJsonPath('titulo', 'Hola qué debería comer hoy mi…');
    }

    public function test_una_clave_de_idempotencia_repetida_devuelve_el_mismo_mensaje_sin_responder_otra_vez(): void
    {
        Queue::fake();

        $primero = $this->enviarTexto('uno', ['idempotency_key' => 'clave_1'])->assertOk()->json();
        $segundo = $this->enviarTexto('otro texto distinto', ['idempotency_key' => 'clave_1'])->assertOk()->json();

        $this->assertSame($primero['message_id'], $segundo['message_id']);
        $this->assertSame('uno', $segundo['content'][0]['texto']);
        $this->assertSame(2, ChatMensaje::count());
        Queue::assertPushed(ResponderChat::class, 1);
    }

    public function test_un_envio_que_falla_no_deja_nada_ni_gasta_secuencia(): void
    {
        $this->enviarTexto('')->assertStatus(422);
        $this->enviarTexto('ok')->assertOk()->assertJsonPath('sequence', 1);
        $this->assertSame([1, 2], ChatMensaje::orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_las_secuencias_son_consecutivas_sin_huecos_ni_repetidos(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->enviarTexto("mensaje $i")->assertOk();
        }

        $this->assertSame(range(1, 24), ChatMensaje::orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(24, $this->api('GET', 'conversacion')->json('ultima_secuencia'));
    }

    public function test_paso_1_estructura(): void
    {
        $malos = [
            [[]],
            [['content' => []]],
            [['content' => 'hola']],
            [['content' => [['tipo' => 'texto']]]],
            [['content' => [['texto' => 'x']]]],
            [['content' => [['tipo' => 'archivo', 'hash' => str_repeat('a', 64), 'mime' => 'image/png', 'tamano' => 1]]]],
            [['content' => [['tipo' => 'peticion_secreto', 'request_id' => 'r', 'nombre' => 'N']]]],
            [['content' => [['tipo' => 'texto', 'texto' => 'x']], 'idempotency_key' => 'con espacios']],
            [['content' => [['tipo' => 'texto', 'texto' => 'x']], 'idempotency_key' => str_repeat('a', 65)]],
            [['content' => [['tipo' => 'imagen', 'hash' => 'corto', 'mime' => 'image/png', 'tamano' => 1]]]],
            [['content' => [['tipo' => 'imagen', 'hash' => str_repeat('a', 64), 'mime' => 'image/png', 'tamano' => 0]]]],
            [['content' => [['tipo' => 'imagen', 'hash' => str_repeat('a', 64), 'tamano' => 3]]]],
            [['content' => [['tipo' => 'imagen', 'hash' => str_repeat('a', 64), 'mime' => 'audio/wav', 'tamano' => 3]]]],
            [['content' => [['tipo' => 'audio', 'hash' => str_repeat('a', 64), 'mime' => 'image/png', 'tamano' => 3]]]],
        ];
        foreach ($malos as $i => [$cuerpo]) {
            $this->api('POST', 'mensajes', $cuerpo)->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        }

        $this->call('POST', '/interno/chat/mensajes', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::SECRETO_CHAT], '{no es json')
            ->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->call('POST', '/interno/chat/mensajes', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::SECRETO_CHAT], '{"content":[{"tipo":"texto","texto":"\ud800"}]}')
            ->assertStatus(422);
        $this->assertSame(0, ChatMensaje::count());
    }

    public function test_texto_con_solo_espacios_o_adjunto_sin_texto_es_valido(): void
    {
        $this->enviarTexto('   ')->assertOk();

        $png = $this->bytesPng();
        $this->subir($png)->assertOk();
        $this->api('POST', 'mensajes', ['content' => [$this->parteImagen($png)]])->assertOk()->assertJsonPath('content.0.tipo', 'imagen');
    }

    public function test_cinco_adjuntos_es_bad_request_y_cuatro_pasan(): void
    {
        $png = $this->bytesPng();
        $this->subir($png)->assertOk();
        $imagenes = array_fill(0, 5, $this->parteImagen($png));

        $this->api('POST', 'mensajes', ['content' => $imagenes])->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->api('POST', 'mensajes', ['content' => array_slice($imagenes, 0, 4)])->assertOk();
    }

    public function test_paso_2_tamanos(): void
    {
        $this->enviarTexto(str_repeat('a', 16384))->assertOk();
        $this->enviarTexto(str_repeat('a', 16385))->assertStatus(413)->assertJsonPath('codigo', 'too_large');
        $this->enviarTexto(str_repeat('ñ', 8192))->assertOk();
        $this->enviarTexto(str_repeat('ñ', 8193))->assertStatus(413);   // 16 386 bytes, 8 193 caracteres

        $this->enviarTexto('hola', ['corrector' => ['relleno' => str_repeat('x', 140000)]])->assertStatus(413);

        $grande = ['tipo' => 'imagen', 'hash' => str_repeat('a', 64), 'mime' => 'image/png', 'tamano' => 4194305];
        $this->api('POST', 'mensajes', ['content' => [$grande]])->assertStatus(413)->assertJsonPath('codigo', 'too_large');
    }

    public function test_paso_3_tipo_no_admitido_y_audio(): void
    {
        $pdf = ['tipo' => 'imagen', 'hash' => str_repeat('a', 64), 'mime' => 'application/pdf', 'tamano' => 3];
        $this->api('POST', 'mensajes', ['content' => [$pdf]])->assertStatus(415)->assertJsonPath('codigo', 'unsupported_media');

        $audio = ['tipo' => 'audio', 'hash' => str_repeat('a', 64), 'mime' => 'audio/wav', 'tamano' => 3];
        $this->api('POST', 'mensajes', ['content' => [$audio]])->assertStatus(415);

        $mayusculas = ['tipo' => 'imagen', 'hash' => str_repeat('a', 64), 'mime' => 'Application/PDF; x=1', 'tamano' => 3];
        $this->api('POST', 'mensajes', ['content' => [$mayusculas]])->assertStatus(415);
    }

    public function test_orden_de_las_validaciones(): void
    {
        $hash = str_repeat('a', 64);
        $pdf = ['tipo' => 'imagen', 'hash' => $hash, 'mime' => 'application/pdf', 'tamano' => 3];
        $pdfGrande = array_merge($pdf, ['tamano' => 5_000_000]);
        $png = ['tipo' => 'imagen', 'hash' => $hash, 'mime' => 'image/png', 'tamano' => 3];

        // 1 antes que 2: clave mala y texto enorme.
        $this->enviarTexto(str_repeat('a', 20000), ['idempotency_key' => 'mala clave'])->assertJsonPath('codigo', 'bad_request');
        // 2 antes que 3.
        $this->api('POST', 'mensajes', ['content' => [$pdfGrande]])->assertJsonPath('codigo', 'too_large');
        // 3 antes que 4.
        $this->api('POST', 'mensajes', ['content' => [$pdf], 'reply_to' => 'no-existe'])->assertJsonPath('codigo', 'unsupported_media');
        // 4 antes que 5.
        $this->api('POST', 'mensajes', ['content' => [$png], 'reply_to' => 'no-existe'])->assertStatus(404)->assertJsonPath('codigo', 'not_found');
        // 5: el archivo no esta.
        $this->api('POST', 'mensajes', ['content' => [$png]])->assertStatus(409)->assertJsonPath('codigo', 'file_missing');

        // 6 antes que 7 y 8: con el historial lleno, la misma clave devuelve el mensaje.
        Queue::fake();
        $primero = $this->enviarTexto('uno', ['idempotency_key' => 'kk'])->assertOk()->json('message_id');
        config(['chat.max_historial' => 2, 'chat.max_en_espera' => 1]);
        $this->enviarTexto('otro', ['idempotency_key' => 'kk'])->assertOk()->assertJsonPath('message_id', $primero);
        // 7 antes que 8.
        $this->enviarTexto('nuevo')->assertStatus(507)->assertJsonPath('codigo', 'history_full');
        config(['chat.max_historial' => 100]);
        $this->enviarTexto('nuevo')->assertStatus(503)->assertJsonPath('codigo', 'busy');
        $this->assertSame(2, ChatMensaje::count());
    }

    public function test_reply_to_existente_se_acepta(): void
    {
        $primero = $this->enviarTexto('uno')->json('message_id');

        $this->enviarTexto('dos', ['reply_to' => $primero])->assertOk()->assertJsonPath('reply_to', $primero);
    }

    public function test_history_full_con_el_limite_por_defecto_configurado(): void
    {
        config(['chat.max_historial' => 4]);

        $this->enviarTexto('a')->assertOk();
        $this->enviarTexto('b')->assertOk();
        $this->enviarTexto('c')->assertStatus(507);
        $this->assertSame(4, ChatMensaje::count());
    }

    public function test_busy_cuando_hay_ocho_respuestas_abiertas(): void
    {
        Queue::fake();

        for ($i = 0; $i < 8; $i++) {
            $this->enviarTexto("m$i")->assertOk();
        }
        $this->enviarTexto('uno mas')->assertStatus(503)->assertJsonPath('codigo', 'busy');

        $respuesta = $this->mensajePorSecuencia(2);
        $respuesta->finalizar();
        $this->enviarTexto('ahora si')->assertOk();
    }

    public function test_leer_mensajes_con_despues_antes_limite_y_ultimos(): void
    {
        Queue::fake();
        for ($i = 1; $i <= 5; $i++) {
            $this->enviarTexto("m$i")->assertOk();   // secuencias 1..10 (usuario y respuesta)
        }
        $secuencias = fn (string $consulta): array => array_column($this->api('GET', 'mensajes'.$consulta)->assertOk()->json('mensajes'), 'sequence');

        $this->assertSame(range(1, 10), $secuencias(''));
        $this->assertSame(range(4, 10), $secuencias('?despues=3'));
        $this->assertSame([4, 5, 6], $secuencias('?despues=3&antes=7'));
        $this->assertSame([], $secuencias('?despues=3&antes=4'));
        $this->assertSame([], $secuencias('?despues=10'));
        $this->assertSame([1, 2, 3], $secuencias('?limite=3'));
        $this->assertSame([8, 9, 10], $secuencias('?ultimos=3'));
        $this->assertSame([5, 6, 7], $secuencias('?despues=2&antes=8&ultimos=3'));
        $this->assertSame(range(1, 10), $secuencias('?limite=0&ultimos=0'));

        foreach (['?limite=2&ultimos=2', '?despues=11', '?despues=-1', '?limite=abc', '?antes=1.5'] as $mala) {
            $this->api('GET', 'mensajes'.$mala)->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        }
    }

    public function test_el_limite_por_defecto_de_lectura_es_500(): void
    {
        config(['chat.limite_lectura' => 3]);
        Queue::fake();
        for ($i = 1; $i <= 3; $i++) {
            $this->enviarTexto("m$i");
        }

        $this->assertCount(3, $this->api('GET', 'mensajes')->json('mensajes'));
        $this->assertCount(3, $this->api('GET', 'mensajes?limite=100')->json('mensajes'));
        $this->assertSame(4, $this->api('GET', 'mensajes?ultimos=100')->json('mensajes.0.sequence'));
    }

    public function test_mostrar_un_mensaje_y_404(): void
    {
        $id = $this->enviarTexto('hola')->json('message_id');

        $this->api('GET', "mensajes/$id")->assertOk()->assertJsonPath('message_id', $id)->assertJsonPath('metadata', []);
        $this->api('GET', 'mensajes/no-existe')->assertStatus(404)->assertJsonPath('codigo', 'not_found');
    }

    public function test_el_metadata_vacio_viaja_como_objeto(): void
    {
        $id = $this->enviarTexto('hola')->json('message_id');

        $crudo = $this->call('GET', "/interno/chat/mensajes/$id", [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::SECRETO_CHAT])->getContent();

        $this->assertStringContainsString('"metadata":{}', $crudo);
    }

    public function test_fijar_cambia_pinned_y_es_idempotente(): void
    {
        Queue::fake();
        $id = $this->enviarTexto('hola')->json('message_id');

        $uno = $this->api('POST', "mensajes/$id/fijar", ['fijado' => true])->assertOk()->assertJsonPath('pinned', true)->json();
        $this->assertGreaterThanOrEqual($uno['created_ms'], $uno['updated_ms']);
        usleep(5000);
        $dos = $this->api('POST', "mensajes/$id/fijar", ['fijado' => true])->assertOk()->json();
        $this->assertSame($uno['updated_ms'], $dos['updated_ms']);
        $this->api('POST', "mensajes/$id/fijar", ['fijado' => false])->assertOk()->assertJsonPath('pinned', false);

        $this->api('POST', "mensajes/$id/fijar", ['fijado' => 'si'])->assertStatus(422);
        $this->api('POST', "mensajes/$id/fijar")->assertStatus(422);
        $this->api('POST', 'mensajes/nada/fijar', ['fijado' => true])->assertStatus(404);
    }

    public function test_cancelar_una_respuesta_abierta_y_errores(): void
    {
        Queue::fake();
        $usuario = $this->enviarTexto('hola')->json('message_id');
        $respuesta = $this->mensajePorSecuencia(2)->message_id;

        $this->api('POST', "mensajes/$respuesta/cancelar")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->api('POST', "mensajes/$respuesta/cancelar")->assertStatus(409)->assertJsonPath('codigo', 'bad_state');
        $this->api('POST', "mensajes/$usuario/cancelar")->assertStatus(409)->assertJsonPath('codigo', 'bad_state');
        $this->api('POST', 'mensajes/nada/cancelar')->assertStatus(404)->assertJsonPath('codigo', 'not_found');
    }

    public function test_auditoria_del_chat_sin_el_texto_completo_ni_ruido_de_deltas(): void
    {
        Queue::fake();
        $texto = 'palabra '.str_repeat('larga ', 300);
        $this->enviarTexto($texto)->assertOk();
        $respuesta = $this->mensajePorSecuencia(2);

        $recibido = Auditoria::where('evento', 'chat.mensaje_recibido')->get();
        $this->assertCount(1, $recibido);
        $this->assertSame(1, $recibido[0]->datos['sequence']);
        $this->assertStringNotContainsString('palabra', json_encode($recibido[0]->datos));

        $respuesta->anadirTexto('trozo 0 ');   // el primer trozo pasa la respuesta a «escribiendo»: un cambio de estado, que si cuenta
        $antes = Auditoria::count();
        foreach (range(1, 10) as $i) {
            $respuesta->anadirTexto("trozo $i ");
        }
        $this->assertSame($antes, Auditoria::count(), 'los deltas (solo contenido y razonamiento) no son un evento cada uno');

        $respuesta->finalizar(['Gracias']);
        $filas = Auditoria::where('auditable_type', ChatMensaje::class)->where('evento', 'actualizado')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(1, $filas->count());
        $final = $filas->last();
        $this->assertSame(EstadoChat::Completado->value, $final->valores_nuevos['estado']);
        $this->assertStringContainsString('[omitido:', json_encode($final->valores_nuevos));
        $this->assertStringNotContainsString('trozo', json_encode(Auditoria::where('auditable_type', ChatMensaje::class)->get()->map->getAttributes()));
        $this->assertSame([], $this->tablasQueContienenAuditoria($texto));
    }

    /** @return array<int, string> */
    private function tablasQueContienenAuditoria(string $texto): array
    {
        return Auditoria::all()->filter(fn (Auditoria $a): bool => str_contains((string) json_encode($a->getAttributes(), JSON_UNESCAPED_UNICODE), $texto))->pluck('id')->all();
    }
}
