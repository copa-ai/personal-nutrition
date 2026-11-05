<?php

namespace App\Http\Controllers;

use App\Models\ChatArchivo;
use Symfony\Component\HttpFoundation\Response;

/** Sirve una imagen guardada en chat_archivos. Solo con sesion del panel (middleware de la ruta). */
class FotoController extends Controller
{
    public function __invoke(string $hash): Response
    {
        $archivo = ChatArchivo::query()->where('hash', $hash)->first();
        if ($archivo === null || ! in_array($archivo->mime, (array) config('chat.tipos_mime'), true)) {
            abort(404);
        }

        return response($archivo->bytes(), 200, [
            'Content-Type' => $archivo->mime,
            'Content-Disposition' => 'inline',
            // El hash identifica el contenido: no cambia nunca.
            'Cache-Control' => 'private, max-age=86400, immutable',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
