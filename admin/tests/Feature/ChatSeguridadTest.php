<?php

namespace Tests\Feature;

use App\Filament\Pages\AjustesServicio;
use App\Http\Middleware\AutoLogin;
use App\Http\Middleware\ChatInterno;
use App\Models\Ajuste;
use App\Models\ChatArchivo;
use App\Models\ChatConversacion;
use App\Models\ChatMensaje;
use App\Models\RegistroComida;
use App\Models\User;
use App\Services\Chat\Claude\AccionesChat;
use App\Services\Chat\Marcado\Validador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\PreparaChatClaude;
use Tests\TestCase;

/** Revision de seguridad del chat: frontera de la API interna, topes de tamano, acciones de Claude, saneado por trozos y auto-login. */
class ChatSeguridadTest extends TestCase
{
    use PreparaChatClaude, RefreshDatabase;

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

    // ------------------------------------------------------------------ frontera

    public function test_la_api_interna_solo_atiende_la_ruta_tal_cual(): void
    {
        $middleware = new ChatInterno;
        foreach (['/index.php/interno/chat/salud', '//interno/chat/salud', '/x/interno/chat/salud'] as $uri) {
            $peticion = Request::create('/interno/chat/salud', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::SECRETO_CHAT]);
            $peticion->server->set('REQUEST_URI', $uri);

            $respuesta = $middleware->handle($peticion, fn () => response('llegó'));

            $this->assertSame(404, $respuesta->getStatusCode(), $uri);
        }
        $this->api('GET', 'salud')->assertOk();
    }

    public function test_el_caddyfile_no_sirve_la_api_interna_por_el_puerto_publico(): void
    {
        $ruta = base_path('../docker/servicio/Caddyfile');
        if (! is_file($ruta)) {
            $this->markTestSkipped('El Caddyfile no está montado en este entorno.');
        }
        $caddyfile = (string) file_get_contents($ruta);
        [$publico, $interno] = explode('http://127.0.0.1:8001', $caddyfile, 2);

        $this->assertMatchesRegularExpression('/path_regexp\s+\S+\s+\(\?i\)interno\/chat\s+handle\s+@\S+\s*\{\s*respond\s+"[^"]*"\s+404/s', $publico);
        $this->assertLessThan(strpos($publico, 'php_server'), strpos($publico, 'interno/chat'), 'El bloqueo debe ir antes de php_server');
        $this->assertStringContainsString('bind 127.0.0.1', $interno);
        $this->assertStringContainsString('handle /interno/chat/*', $interno);
    }

    // ------------------------------------------------------------------ archivos

    public function test_el_total_de_archivos_tiene_tope(): void
    {
        $uno = $this->bytesPng();
        config(['chat.max_total_archivos_bytes' => strlen($uno) + 10]);

        $this->subir($uno)->assertOk();
        $dos = $uno."\x00otro";
        $this->subir($dos)->assertStatus(507)->assertJsonPath('codigo', 'storage_full');
        $this->assertSame(1, ChatArchivo::count());
        $this->subir($uno)->assertOk();   // ya existe: no ocupa mas
    }

    public function test_el_nombre_del_archivo_se_limpia(): void
    {
        $bytes = $this->bytesPng();

        $this->subir($bytes, null, ['X-Nombre' => rawurlencode("foto\0\x01\xff.png\n")])->assertOk();

        $this->assertSame('foto?.png', ChatArchivo::query()->firstOrFail()->nombre);
    }

    // ------------------------------------------------------------------ lecturas acotadas

    private function llenar(int $mensajes, int $bytes): void
    {
        for ($i = 1; $i <= $mensajes; $i++) {
            $m = new ChatMensaje;
            $m->forceFill([
                'message_id' => "m{$i}", 'sequence' => $i, 'contenido' => [['tipo' => 'texto', 'texto' => str_repeat('a', $bytes)]], 'estado' => 'COMPLETED',
                'from_service' => true, 'razonamiento' => '', 'pinned' => false, 'metadata' => [], 'sugerencias' => [],
                'created_ms' => $i, 'updated_ms' => $i,
            ])->save();
        }
        ChatConversacion::unica()->forceFill(['ultima_secuencia' => $mensajes])->save();
    }

    public function test_una_lectura_no_devuelve_mas_que_el_presupuesto_de_bytes(): void
    {
        config(['chat.max_bytes_lectura' => 1000]);
        $this->llenar(10, 400);

        $r = $this->api('GET', 'mensajes')->assertOk();
        $this->assertCount(2, $r->json('mensajes'));
        $this->assertSame(1, $r->json('mensajes.0.sequence'));

        $r = $this->api('GET', 'mensajes?ultimos=5')->assertOk();
        $this->assertSame([9, 10], array_column($r->json('mensajes'), 'sequence'));

        config(['chat.max_bytes_lectura' => 1]);   // al menos uno siempre
        $this->assertCount(1, $this->api('GET', 'mensajes')->json('mensajes'));
    }

    public function test_sincronizar_entrega_los_nuevos_por_trozos_y_limita_los_abiertos(): void
    {
        config(['chat.limite_lectura' => 3]);
        $this->llenar(7, 10);

        $r = $this->api('POST', 'sincronizar', ['despues' => 0, 'abiertos' => [], 'titulo' => null])->assertOk();
        $this->assertSame(7, $r->json('ultima_secuencia'));
        $this->assertSame([1, 2, 3], array_column($r->json('nuevos'), 'sequence'));
        $r = $this->api('POST', 'sincronizar', ['despues' => 3, 'abiertos' => [], 'titulo' => null]);
        $this->assertSame([4, 5, 6], array_column($r->json('nuevos'), 'sequence'));

        config(['chat.max_abiertos_sincronizar' => 2]);
        $abiertos = array_map(fn (int $i): array => ['id' => "m{$i}", 'texto' => 0, 'razonamiento' => 0, 'estado' => 'IN_PROGRESS', 'fijado' => false], [1, 2, 3]);
        $this->api('POST', 'sincronizar', ['despues' => 7, 'abiertos' => $abiertos, 'titulo' => null])->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
    }

    // ------------------------------------------------------------------ acciones de Claude

    public function test_no_se_corrige_un_registro_de_un_perfil_dado_de_baja(): void
    {
        $registro = RegistroComida::create(['perfil_id' => $this->javier->id, 'fecha' => '2026-11-05', 'momento' => 'comida', 'origen' => 'casa', 'descripcion' => 'Pasta']);
        $this->javier->update(['activo' => false]);

        $r = app(AccionesChat::class)->procesar((string) json_encode(['acciones' => [['tipo' => 'corregir_comida', 'registro_id' => $registro->id, 'descripcion' => 'Otra cosa']]]));

        $this->assertSame('rechazada', $r[0]['estado']);
        $this->assertSame('Pasta', $registro->fresh()->descripcion);
    }

    public function test_el_archivo_de_acciones_se_lee_con_tope_y_se_rechaza(): void
    {
        $max = (int) config('chat_claude.acciones.max_bytes_archivo');
        file_put_contents($this->carpeta.'/'.AccionesChat::relativa(5), str_repeat(' ', $max * 4).'{"acciones":[]}');

        $leido = AccionesChat::leerArchivo(5);

        $this->assertSame($max + 1, strlen((string) $leido));
        $this->assertSame('rechazada', app(AccionesChat::class)->procesar((string) $leido)[0]['estado']);
    }

    public function test_un_enlace_simbolico_como_archivo_de_acciones_no_se_lee(): void
    {
        $secreto = $this->carpeta.'/fuera.json';
        file_put_contents($secreto, '{"acciones":[]}');
        symlink($secreto, $this->carpeta.'/'.AccionesChat::relativa(6));

        $this->assertNull(AccionesChat::leerArchivo(6));
    }

    // ------------------------------------------------------------------ saneado por trozos

    public function test_una_valla_ajena_abierta_no_deja_pasar_html_en_el_trozo_siguiente(): void
    {
        $uno = Validador::saneaTrozo("Mira:\n```text\nsolo codigo");
        $dos = Validador::saneaTrozo("```\n```html\n<script>alert(1)</script><p onclick=\"x()\">hola</p>\n```\n");

        $this->assertStringEndsWith('```', rtrim($uno));
        $this->assertSame(0, substr_count($uno, '```') % 2);
        // Lo que ve el cliente al concatenar los trozos ya es canonico: ningun bloque html queda fuera de un bloque de codigo sin sanear.
        $this->assertSame($uno."\n".$dos, Validador::sanear($uno."\n".$dos));
        $sinArreglo = "Mira:\n```text\nsolo codigo\n```\n```html\n<script>alert(1)</script>\n```\n";
        $this->assertNotSame($sinArreglo, Validador::sanear($sinArreglo));
        // sanear() (el de un texto entero) no cambia.
        $this->assertSame("```text\nabc", Validador::sanear("```text\nabc"));
    }

    // ------------------------------------------------------------------ auto-login

    public function test_el_autologin_tiene_tope_por_minuto(): void
    {
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=']);
        $usuario = User::create(['name' => 'Demo', 'email' => 'admin@demo.com', 'password' => 'password']);
        Ajuste::fijar(Ajuste::AUTOLOGIN_USUARIO_ID, $usuario->id);
        Ajuste::fijar(Ajuste::AUTOLOGIN_ACTIVO, true);
        RateLimiter::clear('autologin');
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('autologin', 60);
        }

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();

        RateLimiter::clear('autologin');
        $this->get('/')->assertRedirect(url('/'));
        $this->assertAuthenticatedAs($usuario);
        $this->assertTrue(class_exists(AutoLogin::class));
    }

    public function test_el_usuario_del_autologin_solo_puede_ser_uno_existente(): void
    {
        $usuario = User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']);
        $this->actingAs($usuario);
        Ajuste::fijar(Ajuste::AUTOLOGIN_USUARIO_ID, $usuario->id);

        Livewire::test(AjustesServicio::class)->set('data.autologin_usuario_id', 98765);

        $this->assertSame($usuario->id, Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID));
    }
}
