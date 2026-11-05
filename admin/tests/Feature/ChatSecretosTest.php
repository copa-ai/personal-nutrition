<?php

namespace Tests\Feature;

use App\Jobs\ResponderChat;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use App\Services\Chat\Respondedor;
use App\Services\Chat\Secretos;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApiChat;
use Tests\TestCase;

/** Motor de prueba: sin token de Claude (y sin omision previa) lo pide; con token responde; omitido, responde sin Claude. */
class MotorQuePideToken implements Respondedor
{
    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        if (TokenClaude::existe()) {
            $respuesta->anadirTexto('Con Claude');
        } elseif (Secretos::fueOmitido('CLAUDE_CODE_OAUTH_TOKEN')) {
            $respuesta->anadirTexto('Sin Claude');
        } else {
            $respuesta->pedirSecreto('peticion-1', 'CLAUDE_CODE_OAUTH_TOKEN', 'Necesito tu token de Claude para razonar mejor.');

            return;
        }
        $respuesta->finalizar();
    }
}

class ChatSecretosTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    private const TOKEN = 'sk-ant-oat01-ESTE-VALOR-NO-DEBE-APARECER-EN-NINGUN-SITIO-9f8e7d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararChat();
        $this->app->bind(Respondedor::class, MotorQuePideToken::class);
    }

    /** @return array<string, mixed> el mensaje con la peticion */
    private function conversacionQuePide(): array
    {
        $this->enviarTexto('Qué como hoy')->assertOk();

        return ChatMensaje::query()->orderBy('sequence')->get()->first(fn (ChatMensaje $m): bool => ($m->contenido[0]['tipo'] ?? null) === 'peticion_secreto')->paraApi();
    }

    public function test_el_motor_pide_el_token_con_un_mensaje_completado(): void
    {
        $peticion = $this->conversacionQuePide();

        $this->assertSame('COMPLETED', $peticion['status']);
        $this->assertTrue($peticion['from_service']);
        $this->assertSame(3, $peticion['sequence']);
        $this->assertEquals(   // jsonb no conserva el orden de las claves
            [['tipo' => 'peticion_secreto', 'request_id' => 'peticion-1', 'nombre' => 'CLAUDE_CODE_OAUTH_TOKEN', 'prosa' => 'Necesito tu token de Claude para razonar mejor.']],
            $peticion['content'],
        );
        // La respuesta vacia que estaba en marcha se cierra: no queda abierta para siempre.
        $this->assertSame('CANCELLED', $this->mensajePorSecuencia(2)->estado->value);
        $this->assertSame('pendiente', ChatPeticionSecreto::firstOrFail()->estado);
    }

    public function test_responder_con_el_valor_guarda_el_token_y_reintenta_la_respuesta(): void
    {
        $peticion = $this->conversacionQuePide();

        $this->api('POST', 'secretos', ['message_id' => $peticion['message_id'], 'request_id' => 'peticion-1', 'valor' => self::TOKEN, 'omitido' => false])
            ->assertOk()->assertExactJson([]);

        $this->assertSame(self::TOKEN, TokenClaude::obtener());
        $nueva = $this->mensajePorSecuencia(4);
        $this->assertTrue($nueva->from_service);
        $this->assertSame($peticion['message_id'], $nueva->reply_to);
        $this->assertSame('Con Claude', $nueva->texto());
        $this->assertSame('COMPLETED', $nueva->estado->value);
        $this->assertSame('respondida', ChatPeticionSecreto::firstOrFail()->estado);

        $this->assertSame([], $this->tablasConTexto(self::TOKEN), 'el valor no esta en ninguna tabla');
        $auditoria = Auditoria::where('evento', 'chat.secreto_respondido')->firstOrFail();
        $this->assertEqualsCanonicalizing(['request_id' => 'peticion-1', 'nombre' => 'CLAUDE_CODE_OAUTH_TOKEN', 'resultado' => 'respondida'], $auditoria->datos);
    }

    public function test_la_respuesta_es_unica_e_idempotente(): void
    {
        $peticion = $this->conversacionQuePide();
        $cuerpo = ['message_id' => $peticion['message_id'], 'request_id' => 'peticion-1'];

        $this->api('POST', 'secretos', $cuerpo + ['valor' => self::TOKEN])->assertOk();
        $this->api('POST', 'secretos', $cuerpo + ['valor' => 'otro-valor-distinto'])->assertOk()->assertExactJson([]);
        $this->api('POST', 'secretos', $cuerpo + ['omitido' => true])->assertOk();

        $this->assertSame(self::TOKEN, TokenClaude::obtener());
        $this->assertSame(4, ChatMensaje::count());
        $this->assertSame(1, Auditoria::where('evento', 'chat.secreto_respondido')->count());
        $this->assertSame([], $this->tablasConTexto('otro-valor-distinto'));
    }

    public function test_omitir_hace_que_el_bot_siga_sin_claude(): void
    {
        $peticion = $this->conversacionQuePide();

        $this->api('POST', 'secretos', ['message_id' => $peticion['message_id'], 'request_id' => 'peticion-1', 'omitido' => true])->assertOk();

        $this->assertFalse(TokenClaude::existe());
        $this->assertSame('Sin Claude', $this->mensajePorSecuencia(4)->texto());
        $this->assertSame($peticion['message_id'], $this->mensajePorSecuencia(4)->reply_to);
        $this->assertSame('omitida', ChatPeticionSecreto::firstOrFail()->estado);
    }

    public function test_validaciones_en_orden(): void
    {
        $peticion = $this->conversacionQuePide();
        $id = $peticion['message_id'];

        $this->api('POST', 'secretos', ['message_id' => 'no-existe', 'request_id' => 'peticion-1', 'valor' => 'x'])->assertStatus(404)->assertJsonPath('codigo', 'not_found');
        $this->api('POST', 'secretos', ['message_id' => $id, 'request_id' => 'otra', 'valor' => 'x'])->assertStatus(404);
        $this->api('POST', 'secretos', ['message_id' => $this->mensajePorSecuencia(1)->message_id, 'request_id' => 'peticion-1', 'valor' => 'x'])->assertStatus(404);
        $this->api('POST', 'secretos', ['request_id' => 'peticion-1', 'valor' => 'x'])->assertStatus(422);

        $base = ['message_id' => $id, 'request_id' => 'peticion-1'];
        foreach ([
            $base,
            $base + ['valor' => 'x', 'omitido' => true],
            $base + ['omitido' => false],
            $base + ['valor' => null, 'omitido' => false],
            $base + ['valor' => ''],
            $base + ['valor' => "ab\0cd"],
            $base + ['valor' => 12],
        ] as $malo) {
            $this->api('POST', 'secretos', $malo)->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        }
        $this->api('POST', 'secretos', $base + ['valor' => str_repeat('a', 8193)])->assertStatus(413)->assertJsonPath('codigo', 'too_large');

        $this->assertSame('pendiente', ChatPeticionSecreto::firstOrFail()->estado);
        $this->assertFalse(TokenClaude::existe());

        $this->api('POST', 'secretos', $base + ['valor' => str_repeat('a', 8192)])->assertOk();
        $this->assertTrue(TokenClaude::existe());
    }

    public function test_el_valor_no_llega_a_la_cola_ni_a_ninguna_tabla(): void
    {
        config(['queue.default' => 'database']);
        $this->app->bind(Respondedor::class, MotorQuePideToken::class);
        $this->enviarTexto('Qué como hoy')->assertOk();
        $this->assertSame(1, DB::table('jobs')->count());
        // El job de la pregunta no se ejecuto: se lanza a mano para que el motor pida el token.
        $job = unserialize(json_decode(DB::table('jobs')->first()->payload, true)['data']['command']);
        $this->assertInstanceOf(ResponderChat::class, $job);
        $job->handle(app(Respondedor::class));
        DB::table('jobs')->delete();

        $peticion = ChatMensaje::query()->where('sequence', 3)->firstOrFail();
        $this->api('POST', 'secretos', ['message_id' => $peticion->message_id, 'request_id' => 'peticion-1', 'valor' => self::TOKEN])->assertOk();

        $this->assertSame(1, DB::table('jobs')->count(), 'el reintento se encola');
        $this->assertStringNotContainsString(self::TOKEN, DB::table('jobs')->first()->payload);
        $this->assertSame([], $this->tablasConTexto(self::TOKEN));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode(Auditoria::all()->map->getAttributes()));
    }

    public function test_un_fallo_al_guardar_el_token_no_filtra_el_valor_ni_cambia_el_estado(): void
    {
        $peticion = $this->conversacionQuePide();
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('boom '.self::TOKEN));

        $respuesta = $this->api('POST', 'secretos', ['message_id' => $peticion['message_id'], 'request_id' => 'peticion-1', 'valor' => self::TOKEN]);

        $respuesta->assertStatus(500)->assertJsonPath('codigo', 'service_error');
        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());
        $this->assertSame('pendiente', ChatPeticionSecreto::firstOrFail()->estado);
    }
}
