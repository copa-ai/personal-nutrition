<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Services\Chat\Archivos;
use App\Services\Chat\ErrorChat;
use App\Services\Compra\Mazo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Rutas /interno/cards: el mazo de la lista de la compra, sus eventos con version, las decisiones y las imagenes (docs/chat-api-interna.md, «Cards»). */
class CardsController extends Controller
{
    public function __construct(private readonly Mazo $mazo, private readonly Archivos $archivos) {}

    public function salud(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function mazo(): JsonResponse
    {
        return response()->json($this->mazo->mazo());
    }

    public function eventos(Request $request): JsonResponse
    {
        $despues = $request->query('despues', '0');
        if (! is_string($despues) || preg_match('/^[0-9]{1,18}$/', $despues) !== 1) {
            throw ErrorChat::badRequest('«despues» debe ser un entero.');
        }

        return response()->json($this->mazo->eventos((int) $despues));
    }

    public function accion(Request $request): JsonResponse
    {
        $cuerpo = json_decode($request->getContent(), true);
        if (! is_array($cuerpo)) {
            throw ErrorChat::badRequest('El cuerpo debe ser un objeto JSON.');
        }
        $this->mazo->decidir($cuerpo);

        return response()->json((object) []);
    }

    public function archivo(string $hex): Response
    {
        $hash = Archivos::normalizarHash($hex);
        $archivo = $hash === null ? null : $this->archivos->buscar($hash);
        if (! $archivo) {
            throw ErrorChat::notFound('El archivo no existe.');
        }

        return response($archivo->bytes(), 200, ['Content-Type' => $archivo->mime, 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }
}
