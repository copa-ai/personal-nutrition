<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\ChatArchivo;
use App\Models\ChatMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiChat;
use Tests\TestCase;

class ChatArchivosTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararChat();
    }

    public function test_subir_guardar_y_descargar_bytes_identicos(): void
    {
        $bytes = '';
        for ($i = 0; $i < 256; $i++) {
            $bytes .= chr($i);
        }
        $bytes .= "\\x00\\\\ \0\xff'\"".$this->bytesPng();
        $hash = hash('sha3-256', $bytes);

        $this->subir($bytes, null, ['X-Nombre' => rawurlencode('Comida ñam 🍝.png')])->assertOk()
            ->assertExactJson(['hash' => $hash, 'mime' => 'image/png', 'tamano' => strlen($bytes), 'nombre' => 'Comida ñam 🍝.png']);

        $descarga = $this->api('GET', "archivos/$hash")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $descarga->getContent());
        $this->assertSame($bytes, ChatArchivo::query()->where('hash', $hash)->firstOrFail()->bytes());
    }

    public function test_dos_subidas_iguales_dejan_una_fila_y_responden_200(): void
    {
        $png = $this->bytesPng();

        $uno = $this->subir($png, null, ['X-Nombre' => 'a.png'])->assertOk()->json();
        $dos = $this->subir($png, null, ['X-Nombre' => 'otro.png', 'X-Mime' => 'IMAGE/PNG'])->assertOk()->json();

        $this->assertSame($uno, $dos);
        $this->assertSame(1, ChatArchivo::count());
        $this->assertSame(1, Auditoria::where('evento', 'chat.archivo_subido')->count());
    }

    public function test_hash_incorrecto_o_tamano_declarado_distinto(): void
    {
        $png = $this->bytesPng();

        $this->subir($png, hash('sha3-256', 'otra cosa'))->assertStatus(422)->assertJsonPath('codigo', 'hash_mismatch');
        $this->subir($png, null, ['X-Tamano' => (string) (strlen($png) + 1)])->assertStatus(422)->assertJsonPath('codigo', 'hash_mismatch');
        $this->subir($png, hash('sha256', $png))->assertStatus(422)->assertJsonPath('codigo', 'hash_mismatch');   // un SHA-256 no vale como SHA3-256
        $this->assertSame(0, ChatArchivo::count());
        $this->assertSame(0, Auditoria::where('evento', 'chat.archivo_subido')->count());
    }

    public function test_estructura_tamano_y_tipo_en_ese_orden(): void
    {
        $png = $this->bytesPng();

        $this->subir($png, 'zz')->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->subir($png, str_repeat('A', 63))->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->subir($png, null, ['X-Mime' => ''])->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->subir($png, null, ['X-Tamano' => 'mucho'])->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->subir($png, null, ['X-Tamano' => '0'])->assertStatus(422)->assertJsonPath('codigo', 'bad_request');
        $this->subir('')->assertStatus(422)->assertJsonPath('codigo', 'bad_request');

        $grande = str_repeat('x', 4194305);
        $this->subir($grande)->assertStatus(413)->assertJsonPath('codigo', 'too_large');
        $this->subir($grande, null, ['X-Mime' => 'application/pdf'])->assertStatus(413)->assertJsonPath('codigo', 'too_large');   // tamano antes que tipo
        $this->subir($png, null, ['X-Mime' => 'application/pdf'])->assertStatus(415)->assertJsonPath('codigo', 'unsupported_media');
        $this->subir($png, null, ['X-Mime' => 'audio/wav'])->assertStatus(415);
        $this->subir($png, null, ['X-Mime' => 'image/gif'])->assertStatus(415);

        $justo = str_repeat('x', 4194304);
        $this->subir($justo, null, ['X-Mime' => 'image/jpeg'])->assertOk();
        $this->assertSame(1, ChatArchivo::count());
    }

    public function test_el_secreto_es_obligatorio(): void
    {
        $png = $this->bytesPng();

        $this->call('PUT', '/interno/chat/archivos/'.hash('sha3-256', $png), [], [], [], ['HTTP_X_MIME' => 'image/png', 'HTTP_X_TAMANO' => '1'], $png)->assertStatus(401);
        $this->call('GET', '/interno/chat/archivos/'.hash('sha3-256', $png))->assertStatus(401);
    }

    public function test_consultar_devuelve_la_disponibilidad_en_el_mismo_orden(): void
    {
        $png = $this->bytesPng();
        $this->subir($png)->assertOk();
        $hash = hash('sha3-256', $png);
        $otro = str_repeat('0', 64);

        $this->api('POST', 'archivos/consultar', ['hashes' => [$otro, $hash, strtoupper($hash)]])->assertOk()->assertExactJson(['resultados' => [
            ['hash' => $otro, 'disponible' => false], ['hash' => $hash, 'disponible' => true], ['hash' => $hash, 'disponible' => true],
        ]]);
        $this->api('POST', 'archivos/consultar', ['hashes' => []])->assertOk()->assertExactJson(['resultados' => []]);
        $this->api('POST', 'archivos/consultar', ['hashes' => ['mal']])->assertStatus(422);
        $this->api('POST', 'archivos/consultar', [])->assertStatus(422);
    }

    public function test_descargar_inexistente_es_404(): void
    {
        $this->api('GET', 'archivos/'.str_repeat('1', 64))->assertStatus(404)->assertJsonPath('codigo', 'not_found');
        $this->api('GET', 'archivos/mal')->assertStatus(404);
    }

    public function test_una_foto_subida_se_puede_enviar_en_un_mensaje(): void
    {
        Queue::fake();
        $png = $this->bytesPng();
        $this->subir($png, null, ['X-Nombre' => 'plato.png'])->assertOk();

        $mensaje = $this->api('POST', 'mensajes', ['content' => [
            ['tipo' => 'texto', 'texto' => 'Mi comida'],
            $this->parteImagen($png) + ['nombre' => 'plato.png', 'alt' => 'un plato'],
        ]])->assertOk();

        $mensaje->assertJsonPath('content.1.tipo', 'imagen')->assertJsonPath('content.1.hash', hash('sha3-256', $png))
            ->assertJsonPath('content.1.nombre', 'plato.png')->assertJsonPath('content.1.alt', 'un plato');
        $this->assertSame(1, ChatMensaje::where('from_service', false)->count());
    }

    public function test_la_auditoria_no_guarda_el_contenido_del_archivo(): void
    {
        $png = $this->bytesPng();
        $this->subir($png)->assertOk();

        $fila = Auditoria::where('evento', 'chat.archivo_subido')->firstOrFail();
        $this->assertSame(hash('sha3-256', $png), $fila->datos['hash']);
        $this->assertStringNotContainsString(base64_encode($png), json_encode($fila->getAttributes()));

        // El alta de la fila tambien queda (la anota el trigger), pero de los bytes solo su tamano.
        $generica = Auditoria::where('auditable_type', ChatArchivo::class)->firstOrFail();
        $this->assertSame('creado', $generica->evento);
        $this->assertSame(ChatArchivo::idDeClave(hash('sha3-256', $png)), $generica->auditable_id);
        $this->assertSame('[omitido: '.strlen($png).' bytes]', $generica->valores_nuevos['contenido']);
        $this->assertStringNotContainsString(base64_encode($png), json_encode($generica->getAttributes()));
    }
}
