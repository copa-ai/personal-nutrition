<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Models\Ajuste;
use App\Models\ChatConversacion;
use Illuminate\Http\JsonResponse;

/** GET /salud, /capacidades y /conversacion de la API interna del chat. */
class ChatController extends Controller
{
    public function salud(): JsonResponse
    {
        return response()->json(['habilitado' => Ajuste::chatHabilitado()]);
    }

    public function capacidades(): JsonResponse
    {
        return response()->json([
            'tipos_mime' => array_values(config('chat.tipos_mime')),
            'max_archivo_bytes' => (int) config('chat.max_archivo_bytes'),
            'max_archivos' => (int) config('chat.max_archivos'),
            'max_texto_bytes' => (int) config('chat.max_texto_bytes'),
            'soporta_ramas' => false,
            'soporta_cancelar' => true,
        ]);
    }

    public function conversacion(): JsonResponse
    {
        $conversacion = ChatConversacion::unica();

        return response()->json([
            'titulo' => $conversacion->titulo === '' ? null : $conversacion->titulo,
            'creada_ms' => (int) $conversacion->creada_ms,
            'ultima_secuencia' => (int) $conversacion->ultima_secuencia,
        ]);
    }
}
