<?php

namespace Tests\Feature;

use App\Models\ChatArchivo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FotosTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\n-bytes-de-prueba";

    private function archivo(string $bytes = self::PNG, string $mime = 'image/png'): ChatArchivo
    {
        return ChatArchivo::create(['hash' => hash('sha3-256', $bytes), 'mime' => $mime, 'tamano' => strlen($bytes), 'nombre' => 'a', 'contenido' => $this->flujo($bytes)]);
    }

    /** El contenido llega a la base como flujo (como en Archivos): con PostgreSQL un string binario no es UTF-8 valido. */
    private function flujo(string $bytes)
    {
        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, $bytes);
        rewind($flujo);

        return $flujo;
    }

    private function entrar(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']));
    }

    public function test_sin_sesion_redirige_al_login(): void
    {
        $archivo = $this->archivo();

        $this->get('/fotos/'.$archivo->hash)->assertRedirect('/login');
    }

    public function test_sirve_los_bytes_con_el_tipo_y_las_cabeceras_correctas(): void
    {
        $this->entrar();
        $archivo = $this->archivo();

        $respuesta = $this->get('/fotos/'.$archivo->hash);

        $respuesta->assertOk();
        $this->assertSame(self::PNG, $respuesta->getContent());
        $respuesta->assertHeader('Content-Type', 'image/png');
        $respuesta->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_sirve_jpeg(): void
    {
        $this->entrar();
        $archivo = $this->archivo("\xFF\xD8\xFFjpeg", 'image/jpeg');

        $this->get('/fotos/'.$archivo->hash)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_hash_inexistente_o_mal_formado_da_404(): void
    {
        $this->entrar();

        $this->get('/fotos/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/fotos/abc')->assertNotFound();
        $this->get('/fotos/'.str_repeat('A', 64))->assertNotFound();
        $this->get('/fotos/'.str_repeat('g', 64))->assertNotFound();
        $this->get('/fotos/'.str_repeat('a', 65))->assertNotFound();
    }

    public function test_un_archivo_que_no_es_imagen_no_se_sirve(): void
    {
        $this->entrar();
        $archivo = $this->archivo('<html>', 'text/html');

        $this->get('/fotos/'.$archivo->hash)->assertNotFound();
    }
}
