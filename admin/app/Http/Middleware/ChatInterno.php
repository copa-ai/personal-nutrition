<?php

namespace App\Http\Middleware;

use App\Models\Ajuste;
use App\Services\Chat\ErrorChat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege /interno/chat/* y /interno/cards/* (la lista de la compra, mismo secreto e interruptor): Bearer con CHAT_SECRETO (401 si falta o no coincide), 503 `deshabilitado` con el chat apagado (salvo
 * /salud) Los errores de las rutas se convierten en JSON en bootstrap/app.php (ErrorChat y fallos inesperados).
 */
class ChatInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        // Defensa en profundidad con el Caddyfile: solo se atiende la ruta tal cual (/interno/chat/... o /interno/cards/...), no por /index.php/... ni
        // con otra forma que el servidor web haya normalizado.
        $ruta = (string) parse_url((string) $request->server('REQUEST_URI'), PHP_URL_PATH);
        if (! str_starts_with($ruta, '/interno/chat/') && ! str_starts_with($ruta, '/interno/cards/')) {
            return ErrorChat::notFound('No existe.')->respuesta();
        }

        $secreto = (string) config('chat.secreto');
        $token = (string) $request->bearerToken();
        if ($secreto === '' || $token === '' || ! hash_equals($secreto, $token)) {
            return response()->json(['codigo' => 'unauthorized', 'mensaje' => 'Falta el secreto del chat o no es correcto.'], 401);
        }

        if (! $request->is('interno/chat/salud', 'interno/cards/salud') && ! Ajuste::chatHabilitado()) {
            return ErrorChat::deshabilitado()->respuesta();
        }

        return $next($request);
    }
}
