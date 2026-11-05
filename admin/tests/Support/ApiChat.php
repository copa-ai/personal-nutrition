<?php

namespace Tests\Support;

use App\Models\ChatMensaje;
use App\Services\Chat\RespondedorEco;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** Ayudas de las pruebas de la API interna del chat (llamadas con el Bearer, archivos de prueba, busqueda en todas las tablas). */
trait ApiChat
{
    protected const SECRETO_CHAT = 'secreto-interno-de-prueba';

    protected function prepararChat(): void
    {
        config(['chat.secreto' => self::SECRETO_CHAT]);
        config(['chat.respondedor_bot' => RespondedorEco::class]);   // las pruebas de transporte esperan el eco; las del bot lo reactivan (UsaBot)
        Storage::fake('local');
    }

    /** @return array<string, string> */
    protected function cabecerasChat(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRETO_CHAT];
    }

    /** @param  array<string, mixed>  $datos */
    protected function api(string $metodo, string $ruta, array $datos = []): TestResponse
    {
        $url = '/interno/chat/'.$ruta;
        if ($metodo === 'GET') {
            return $this->withHeaders($this->cabecerasChat())->getJson($url);
        }

        return $this->withHeaders($this->cabecerasChat())->json($metodo, $url, $datos);
    }

    /** @param  array<string, mixed>  $extra */
    protected function enviarTexto(string $texto, array $extra = []): TestResponse
    {
        return $this->api('POST', 'mensajes', ['content' => [['tipo' => 'texto', 'texto' => $texto]]] + $extra);
    }

    /** Un PNG de 1x1 (bytes reales). */
    protected function bytesPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /** Sube bytes por PUT /archivos/{hex} y devuelve la respuesta. @param  array<string, string>  $cabeceras */
    protected function subir(string $bytes, ?string $hash = null, array $cabeceras = []): TestResponse
    {
        $hash ??= hash('sha3-256', $bytes);
        $servidor = ['HTTP_AUTHORIZATION' => 'Bearer '.self::SECRETO_CHAT, 'CONTENT_TYPE' => 'application/octet-stream'];
        $cabeceras += ['X-Mime' => 'image/png', 'X-Tamano' => (string) strlen($bytes)];
        foreach ($cabeceras as $nombre => $valor) {
            $servidor['HTTP_'.strtoupper(str_replace('-', '_', $nombre))] = $valor;
        }

        return $this->call('PUT', '/interno/chat/archivos/'.$hash, [], [], [], $servidor, $bytes);
    }

    /** Parte de imagen para un envio. @return array<string, mixed> */
    protected function parteImagen(string $bytes, string $mime = 'image/png'): array
    {
        return ['tipo' => 'imagen', 'hash' => hash('sha3-256', $bytes), 'mime' => $mime, 'tamano' => strlen($bytes)];
    }

    /** Tablas (salvo los binarios) cuyo contenido incluye $buscado, en cualquier columna. @return array<int, string> */
    protected function tablasConTexto(string $buscado): array
    {
        $encontradas = [];
        foreach (Schema::getTableListing() as $tabla) {
            if (str_ends_with($tabla, 'chat_archivos') || str_ends_with($tabla, 'migrations')) {
                continue;
            }
            foreach (DB::table($tabla)->get() as $fila) {
                $texto = json_encode($fila, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (str_contains((string) $texto, $buscado) || str_contains((string) $texto, addcslashes($buscado, '/'))) {
                    $encontradas[] = $tabla;
                    break;
                }
            }
        }

        return $encontradas;
    }

    protected function mensajePorSecuencia(int $sequence): ChatMensaje
    {
        return ChatMensaje::query()->where('sequence', $sequence)->firstOrFail();
    }
}
