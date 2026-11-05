<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Services\Chat\ErrorChat;
use App\Services\Chat\Secretos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /secretos de la API interna del chat. El valor no se guarda ni se registra en ningun sitio (ver Secretos). */
class SecretosController extends Controller
{
    public function __construct(private readonly Secretos $secretos) {}

    public function store(Request $request): JsonResponse
    {
        $cuerpo = json_decode($request->getContent(), true);
        $cuerpo = is_array($cuerpo) ? $cuerpo : [];
        $mensaje = $cuerpo['message_id'] ?? null;
        $peticion = $cuerpo['request_id'] ?? null;
        if (! is_string($mensaje) || ! is_string($peticion)) {
            throw ErrorChat::badRequest('Faltan message_id y request_id.');
        }

        $this->secretos->responder($mensaje, $peticion, $cuerpo['valor'] ?? null, $cuerpo['omitido'] ?? null);

        return response()->json((object) []);
    }
}
