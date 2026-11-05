<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Services\Chat\Archivos;
use App\Services\Chat\ErrorChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Rutas /archivos de la API interna del chat: consultar, subir (PUT binario) y descargar. */
class ArchivosController extends Controller
{
    public function __construct(private readonly Archivos $archivos) {}

    public function consultar(Request $request): JsonResponse
    {
        $hashes = json_decode($request->getContent(), true)['hashes'] ?? null;
        if (! is_array($hashes) || ! array_is_list($hashes) || count($hashes) > 1000) {
            throw ErrorChat::badRequest('Falta la lista «hashes».');
        }
        $normales = [];
        foreach ($hashes as $hash) {
            $normales[] = Archivos::normalizarHash($hash) ?? throw ErrorChat::badRequest('Un hash no es un SHA3-256 hexadecimal.');
        }
        $disponibles = $this->archivos->disponibles($normales);

        return response()->json(['resultados' => array_map(
            fn (string $hash): array => ['hash' => $hash, 'disponible' => $disponibles[$hash]],
            $normales,
        )]);
    }

    public function subir(Request $request, string $hex): JsonResponse
    {
        $nombre = $request->header('X-Nombre');
        $archivo = $this->archivos->guardar(
            $hex, $request->getContent(), $request->header('X-Mime'), $request->header('X-Tamano'),
            $nombre === null ? null : rawurldecode($nombre),
        );

        return response()->json($archivo->paraApi());
    }

    public function descargar(string $hex): Response
    {
        $hash = Archivos::normalizarHash($hex);
        $archivo = $hash === null ? null : $this->archivos->buscar($hash);
        if (! $archivo) {
            throw ErrorChat::notFound('El archivo no existe.');
        }

        return response($archivo->bytes(), 200, ['Content-Type' => $archivo->mime, 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }
}
