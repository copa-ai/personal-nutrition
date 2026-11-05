<?php

namespace Tests\Feature;

use App\Enums\EstadoChat;
use App\Jobs\ResponderChat;
use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Services\Chat\Respondedor;
use App\Services\Chat\RespondedorMixto;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\ApiChat;
use Tests\TestCase;

class MotorDePrueba implements Respondedor
{
    /** @var array<int, string> */
    public static array $llamadas = [];

    public static ?\Closure $accion = null;

    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        self::$llamadas[] = static::class.':'.$pregunta->texto();
        (self::$accion)($pregunta, $respuesta);
    }
}

class MotorClaudeDePrueba extends MotorDePrueba {}

class MotorBotDePrueba extends MotorDePrueba {}

class ChatRespuestaTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararChat();
        MotorDePrueba::$llamadas = [];
        MotorDePrueba::$accion = null;
    }

    private function respuestaAbierta(): ChatMensaje
    {
        Queue::fake();
        $this->enviarTexto('hola')->assertOk();

        return $this->mensajePorSecuencia(2);
    }

    public function test_por_defecto_responde_el_eco_a_traves_del_job(): void
    {
        $this->enviarTexto('¿Qué ceno?')->assertOk();

        $respuesta = $this->mensajePorSecuencia(2);
        $this->assertSame('COMPLETED', $respuesta->estado->value);
        $this->assertSame('Eco: ¿Qué ceno?', $respuesta->texto());
        $this->assertSame($this->mensajePorSecuencia(1)->message_id, $respuesta->reply_to);
    }

    public function test_anadir_texto_inicia_y_agrega_a_la_ultima_parte(): void
    {
        $respuesta = $this->respuestaAbierta();
        $this->assertSame(EstadoChat::Pendiente, $respuesta->estado);

        $this->assertTrue($respuesta->anadirTexto('Hola'));
        $this->assertSame(EstadoChat::EnCurso, $respuesta->estado);
        $respuesta->anadirTexto(' mundo');
        $respuesta->anadirTexto('');
        $respuesta->anadirRazonamiento('uno ');
        $respuesta->anadirRazonamiento('dos');

        $fresca = $respuesta->fresh();
        $this->assertSame([['tipo' => 'texto', 'texto' => 'Hola mundo']], $fresca->contenido);
        $this->assertSame('uno dos', $fresca->razonamiento);
        $this->assertSame(EstadoChat::EnCurso, $fresca->estado);
    }

    public function test_finalizar_limita_las_sugerencias_y_es_final(): void
    {
        $respuesta = $this->respuestaAbierta();
        $respuesta->anadirTexto('x');

        $this->assertTrue($respuesta->finalizar(['A', '  ', 'B', 'C', 'D']));
        $this->assertSame(EstadoChat::Completado, $respuesta->estado);
        $this->assertSame(['A', 'B', 'C'], $respuesta->fresh()->sugerencias);
        $this->assertFalse($respuesta->anadirTexto('más'));
        $this->assertFalse($respuesta->finalizar(['otra']));
        $this->assertFalse($respuesta->fallar('tarde'));
        $this->assertSame('x', $respuesta->fresh()->texto());
        $this->assertSame(['A', 'B', 'C'], $respuesta->fresh()->sugerencias);
    }

    public function test_fallar_guarda_un_error_de_hasta_1024_bytes(): void
    {
        $respuesta = $this->respuestaAbierta();
        $respuesta->anadirTexto('parcial');

        $this->assertTrue($respuesta->fallar(str_repeat('ñ', 2000)));
        $fresca = $respuesta->fresh();
        $this->assertSame(EstadoChat::Fallido, $fresca->estado);
        $this->assertSame(1024, strlen($fresca->error));
        $this->assertTrue(mb_check_encoding($fresca->error, 'UTF-8'));
        $this->assertSame('parcial', $fresca->texto());
        $this->assertSame(1024, strlen($this->api('GET', 'mensajes/'.$fresca->message_id)->json('error')));
    }

    public function test_cancelar_corta_las_escrituras_del_motor(): void
    {
        $respuesta = $this->respuestaAbierta();
        $respuesta->anadirTexto('uno');
        $copiaDelMotor = ChatMensaje::find($respuesta->id);   // un modelo desactualizado, como el que tiene el motor

        $this->api('POST', 'mensajes/'.$respuesta->message_id.'/cancelar')->assertOk();

        $this->assertFalse($copiaDelMotor->anadirTexto('dos'));
        $this->assertTrue($copiaDelMotor->cancelada());
        $this->assertFalse($copiaDelMotor->finalizar());
        $fresca = $respuesta->fresh();
        $this->assertSame(EstadoChat::Cancelado, $fresca->estado);
        $this->assertSame('uno', $fresca->texto());
    }

    public function test_el_texto_se_corta_al_llegar_al_limite_de_un_mensaje_y_se_cierra(): void
    {
        config(['chat.max_mensaje_bytes' => 10]);
        $respuesta = $this->respuestaAbierta();

        $this->assertTrue($respuesta->anadirTexto('1234'));
        $this->assertTrue($respuesta->anadirTexto('ñññññññ'), 'la llamada que llena el mensaje se aplica y lo cierra');

        $fresca = $respuesta->fresh();
        $this->assertSame('1234ñññ', $fresca->texto());
        $this->assertSame(EstadoChat::Completado, $fresca->estado);
        $this->assertFalse($respuesta->anadirTexto('más'));
    }

    public function test_pedir_secreto_valida_y_publica_un_mensaje_nuevo(): void
    {
        $respuesta = $this->respuestaAbierta();
        $respuesta->anadirTexto('Para seguir necesito algo.');

        try {
            $respuesta->pedirSecreto('con espacios', 'NOMBRE', 'x');
            $this->fail('debe rechazar el request_id');
        } catch (InvalidArgumentException) {
        }
        try {
            $respuesta->pedirSecreto('r1', '1MAL', 'x');
            $this->fail('debe rechazar el nombre');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(EstadoChat::EnCurso, $respuesta->fresh()->estado, 'una peticion no valida no cambia nada');

        $peticion = $respuesta->pedirSecreto('r1', 'OTRA_CLAVE', str_repeat('p', 2000));

        $this->assertSame(EstadoChat::Completado, $respuesta->fresh()->estado, 'con contenido previo se completa');
        $this->assertSame(3, $peticion->sequence);
        $this->assertSame($respuesta->reply_to, $peticion->reply_to);
        $this->assertSame(1024, strlen($peticion->contenido[0]['prosa']));
        $this->assertSame('peticion_secreto', $this->api('GET', 'mensajes/'.$peticion->message_id)->json('content.0.tipo'));
    }

    public function test_anadir_imagen_publica_un_mensaje_con_la_parte_de_imagen(): void
    {
        $respuesta = $this->respuestaAbierta();
        $png = $this->bytesPng();
        $this->subir($png, null, ['X-Nombre' => 'a.png'])->assertOk();

        $imagen = $respuesta->anadirImagen(strtoupper(hash('sha3-256', $png)), 'un plato');

        $this->assertSame(EstadoChat::Completado, $imagen->estado);
        $this->assertSame(['tipo' => 'imagen', 'hash' => hash('sha3-256', $png), 'mime' => 'image/png', 'tamano' => strlen($png), 'nombre' => 'a.png', 'alt' => 'un plato'], $imagen->contenido[0]);
        $this->assertSame($respuesta->reply_to, $imagen->reply_to);
        $this->assertSame('archivo', $respuesta->anadirArchivo(hash('sha3-256', $png))->contenido[0]['tipo']);

        $this->expectException(InvalidArgumentException::class);
        $respuesta->anadirImagen(str_repeat('0', 64));
    }

    public function test_el_job_finaliza_lo_que_el_motor_deja_abierto(): void
    {
        $this->app->bind(Respondedor::class, MotorDePrueba::class);
        MotorDePrueba::$accion = fn (ChatMensaje $p, ChatMensaje $r) => $r->anadirTexto('sin cerrar');

        $this->enviarTexto('hola');

        $this->assertSame(EstadoChat::Completado, $this->mensajePorSecuencia(2)->estado);
    }

    public function test_el_job_marca_failed_con_un_error_generico_si_el_motor_lanza(): void
    {
        $this->app->bind(Respondedor::class, MotorDePrueba::class);
        MotorDePrueba::$accion = function (ChatMensaje $p, ChatMensaje $r): void {
            $r->anadirTexto('a medias');

            throw new RuntimeException('detalle interno con ruta /etc/secreto');
        };

        $this->enviarTexto('hola')->assertOk();

        $respuesta = $this->mensajePorSecuencia(2);
        $this->assertSame(EstadoChat::Fallido, $respuesta->estado);
        $this->assertSame('a medias', $respuesta->texto());
        $this->assertStringNotContainsString('/etc/secreto', $respuesta->error);
        $this->assertNotSame('', $respuesta->error);
    }

    public function test_el_job_respeta_la_cancelacion_previa_y_la_de_durante(): void
    {
        $this->app->bind(Respondedor::class, MotorDePrueba::class);
        Queue::fake();
        $this->enviarTexto('uno');
        $this->mensajePorSecuencia(2)->cancelar();

        (new ResponderChat($this->mensajePorSecuencia(1)->id, $this->mensajePorSecuencia(2)->id))->handle(app(Respondedor::class));
        $this->assertSame([], MotorDePrueba::$llamadas, 'una respuesta cancelada antes de empezar no llama al motor');

        $this->enviarTexto('dos');
        MotorDePrueba::$accion = function (ChatMensaje $p, ChatMensaje $r): void {
            $r->anadirTexto('empiezo');
            $this->api('POST', 'mensajes/'.$r->message_id.'/cancelar')->assertOk();
            $this->assertFalse($r->anadirTexto('sigo'));
        };
        (new ResponderChat($this->mensajePorSecuencia(3)->id, $this->mensajePorSecuencia(4)->id))->handle(app(Respondedor::class));

        $this->assertSame(EstadoChat::Cancelado, $this->mensajePorSecuencia(4)->estado);
        $this->assertSame('empiezo', $this->mensajePorSecuencia(4)->texto());
    }

    public function test_el_job_failed_cierra_la_respuesta(): void
    {
        $respuesta = $this->respuestaAbierta();

        (new ResponderChat($this->mensajePorSecuencia(1)->id, $respuesta->id))->failed(new RuntimeException('mato el worker'));

        $this->assertSame(EstadoChat::Fallido, $respuesta->fresh()->estado);
    }

    public function test_el_job_solo_lleva_ids(): void
    {
        config(['queue.default' => 'database']);

        $this->enviarTexto('texto privado de la persona')->assertOk();

        $payload = DB::table('jobs')->first()->payload;
        $this->assertStringNotContainsString('texto privado', $payload);
        $this->assertStringContainsString('ResponderChat', $payload);
    }

    public function test_el_mixto_usa_el_bot_sin_token_y_claude_con_token(): void
    {
        config(['chat.respondedor_claude' => MotorClaudeDePrueba::class, 'chat.respondedor_bot' => MotorBotDePrueba::class]);
        MotorDePrueba::$accion = fn (ChatMensaje $p, ChatMensaje $r) => $r->finalizar();
        $respuesta = $this->respuestaAbierta();
        $pregunta = $this->mensajePorSecuencia(1);

        app(RespondedorMixto::class)->responder($pregunta, $respuesta);
        $this->assertSame([MotorBotDePrueba::class.':hola'], MotorDePrueba::$llamadas);

        TokenClaude::guardar('token-de-prueba');
        MotorDePrueba::$llamadas = [];
        app(RespondedorMixto::class)->responder($pregunta, $respuesta);
        $this->assertSame([MotorClaudeDePrueba::class.':hola'], MotorDePrueba::$llamadas);
    }

    public function test_el_mixto_cae_al_bot_si_claude_falla_sin_escribir_nada_y_propaga_si_ya_escribio(): void
    {
        config(['chat.respondedor_claude' => MotorClaudeDePrueba::class, 'chat.respondedor_bot' => MotorBotDePrueba::class]);
        TokenClaude::guardar('token-de-prueba');
        $respuesta = $this->respuestaAbierta();
        $pregunta = $this->mensajePorSecuencia(1);
        MotorDePrueba::$accion = function (ChatMensaje $p, ChatMensaje $r): void {
            if (str_contains(MotorDePrueba::$llamadas[array_key_last(MotorDePrueba::$llamadas)], 'Claude')) {
                throw new RuntimeException('Claude no responde');
            }
            $r->anadirTexto('lo hace el bot');
        };

        app(RespondedorMixto::class)->responder($pregunta, $respuesta);
        $this->assertSame('lo hace el bot', $respuesta->fresh()->texto());
        $this->assertCount(2, MotorDePrueba::$llamadas);

        $otra = $this->respuestaAbierta();
        MotorDePrueba::$accion = function (ChatMensaje $p, ChatMensaje $r): void {
            $r->anadirTexto('ya escribi');

            throw new RuntimeException('Claude se cae a medias');
        };
        $this->expectException(RuntimeException::class);
        app(RespondedorMixto::class)->responder($pregunta, $otra);
    }

    public function test_sin_clases_de_motor_el_mixto_usa_el_eco(): void
    {
        config(['chat.respondedor_claude' => 'No\\Existe\\Claude', 'chat.respondedor_bot' => 'No\\Existe\\Bot']);
        TokenClaude::guardar('token-de-prueba');

        $this->enviarTexto('hola')->assertOk();

        $this->assertSame('Eco: hola', $this->mensajePorSecuencia(2)->texto());
    }

    public function test_ajustes_por_defecto_fijar_y_auditoria(): void
    {
        $this->assertTrue(Ajuste::obtener(Ajuste::CHAT_HABILITADO));
        $this->assertFalse(Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO));
        $this->assertNull(Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID));
        $this->assertSame('x', Ajuste::obtener('inexistente', 'x'));
        $this->assertTrue(Ajuste::chatHabilitado());

        Ajuste::fijar(Ajuste::CHAT_HABILITADO, false);
        Ajuste::fijar(Ajuste::CHAT_HABILITADO, false);
        Ajuste::fijar(Ajuste::AUTOLOGIN_USUARIO_ID, 7);
        Ajuste::fijar(Ajuste::AUTOLOGIN_ACTIVO, true);

        $this->assertFalse(Ajuste::obtener(Ajuste::CHAT_HABILITADO));
        $this->assertSame(7, Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID));
        $this->assertTrue(Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO));
        $this->assertSame(3, DB::table('ajustes')->count());

        $filas = Auditoria::where('evento', 'chat.interruptor_cambiado')->get();
        $this->assertCount(1, $filas, 'repetir el mismo valor no audita');
        $this->assertEquals(['ajuste' => 'chat_habilitado', 'antes' => true, 'despues' => false], $filas[0]->datos);
        $this->assertSame(2, Auditoria::where('evento', 'ajuste.cambiado')->count());
        // Ademas del hecho con nombre, el trigger anota cada alta y cambio de la fila; el nombre del ajuste (`clave`) no se enmascara.
        $genericas = Auditoria::where('auditable_type', Ajuste::class)->orderBy('id')->get();
        $this->assertSame(['creado', 'creado', 'creado'], $genericas->pluck('evento')->all(), 'repetir el mismo valor no cambia la fila');
        $this->assertSame('chat_habilitado', $genericas[0]->valores_nuevos['clave']);
    }

    public function test_el_mixto_no_usa_claude_si_el_interruptor_esta_apagado_aunque_haya_token(): void
    {
        config(['chat.respondedor_claude' => MotorClaudeDePrueba::class, 'chat.respondedor_bot' => MotorBotDePrueba::class]);
        MotorDePrueba::$accion = fn (ChatMensaje $p, ChatMensaje $r) => $r->finalizar();
        TokenClaude::guardar('token-de-prueba');
        $respuesta = $this->respuestaAbierta();
        $pregunta = $this->mensajePorSecuencia(1);

        $this->assertTrue(RespondedorMixto::claudeActivo());
        Ajuste::fijar(Ajuste::CHAT_CLAUDE_HABILITADO, false);

        $this->assertFalse(RespondedorMixto::claudeActivo());
        app(RespondedorMixto::class)->responder($pregunta, $respuesta);
        $this->assertSame([MotorBotDePrueba::class.':hola'], MotorDePrueba::$llamadas);
        $this->assertTrue(TokenClaude::existe(), 'apagar Claude no borra el token');
    }
}
