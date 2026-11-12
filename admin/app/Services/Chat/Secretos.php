<?php

namespace App\Services\Chat;

use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use App\Services\Menu\TokenClaude;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Respuestas a las peticiones de secretos (chat.md §5.6 y §6.9). El valor solo pasa por aqui: no se guarda en ningun mensaje,
 * metadato, log, cola ni fila de auditoria. El unico destino es TokenClaude cuando el secreto es CLAUDE_CODE_OAUTH_TOKEN.
 */
class Secretos
{
    public const NOMBRE_TOKEN_CLAUDE = 'CLAUDE_CODE_OAUTH_TOKEN';

    public function __construct(private readonly Conversacion $conversacion) {}

    /** True si la persona omitio la peticion de este secreto (el bot, por ejemplo, ya no vuelve a pedirlo). */
    public static function fueOmitido(string $nombre): bool
    {
        return ChatPeticionSecreto::query()->where('nombre', $nombre)->where('estado', ChatPeticionSecreto::OMITIDA)->exists();
    }

    /**
     * Responde una peticion: o un valor o una omision. Unica e idempotente: la primera respuesta cuenta y las siguientes no
     * cambian nada. Despues se reintenta la respuesta que esperaba (con o sin el secreto).
     *
     * @throws ErrorChat
     */
    public function responder(string $messageId, string $requestId, mixed $valor, mixed $omitido): void
    {
        // Paso 1.
        $peticion = ChatPeticionSecreto::query()->where('request_id', $requestId)->where('message_id', $messageId)->first();
        if (! $peticion) {
            throw ErrorChat::notFound('La petición de secreto no existe.');
        }
        // Paso 2: exactamente uno de los dos.
        $hayValor = $valor !== null;
        $esOmision = $omitido === true;
        if ($hayValor === $esOmision || ($omitido !== null && ! is_bool($omitido)) || ($hayValor && ! is_string($valor))) {
            throw ErrorChat::badRequest('Hay que dar o un valor o la omisión, no los dos ni ninguno.');
        }
        if ($hayValor) {
            // Pasos 3 y 4.
            if ($valor === '' || ! mb_check_encoding($valor, 'UTF-8') || str_contains($valor, "\0")) {
                throw ErrorChat::badRequest('El valor no es válido.');
            }
            if (strlen($valor) > (int) config('chat.max_secreto_bytes')) {
                throw ErrorChat::tooLarge('El valor es demasiado largo.');
            }
            if ($peticion->nombre === self::NOMBRE_TOKEN_CLAUDE && trim($valor) === '') {
                throw ErrorChat::badRequest('El valor no es válido.');
            }
        }

        // Paso 5.
        try {
            $primera = DB::connection('chat')->transaction(function () use ($peticion, $valor, $hayValor): bool {
                $fila = ChatPeticionSecreto::query()->whereKey($peticion->getKey())->lockForUpdate()->first();
                if ($fila->estado !== ChatPeticionSecreto::PENDIENTE) {
                    return false;
                }
                if ($hayValor && $fila->nombre === self::NOMBRE_TOKEN_CLAUDE) {
                    TokenClaude::guardar($valor);
                }
                $fila->estado = $hayValor ? ChatPeticionSecreto::RESPONDIDA : ChatPeticionSecreto::OMITIDA;
                $fila->save();

                return true;
            });
        } catch (Throwable $e) {
            report(new \RuntimeException('No se pudo guardar el secreto: '.$e::class));   // sin el mensaje original, por si trae el valor

            throw ErrorChat::serviceError('No se pudo guardar el valor.');
        }
        if (! $primera) {
            return;
        }

        $peticion->refresh();
        Auditoria::registrar('chat.secreto_respondido', 'Petición de secreto respondida', [
            'request_id' => $peticion->request_id, 'nombre' => $peticion->nombre, 'resultado' => $peticion->estado,
        ]);

        $mensaje = ChatMensaje::query()->where('message_id', $messageId)->first();
        if ($mensaje) {
            $this->conversacion->reintentarTrasSecreto($mensaje);
        }
    }
}
