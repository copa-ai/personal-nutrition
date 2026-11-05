<?php

namespace App\Services\Chat;

use App\Models\ChatMensaje;

/**
 * Un motor de respuesta del chat (Claude, bot basico, eco). Recibe el mensaje de la persona y la respuesta del servicio ya
 * creada (PENDING, vacia, con reply_to) y la va llenando con la API de escritura de ChatMensaje: anadirTexto(),
 * anadirRazonamiento(), finalizar(), fallar(), pedirSecreto()...
 *
 * Reglas: si una escritura devuelve false, la respuesta ya es final (por ejemplo, cancelada): hay que parar. Si responder()
 * termina sin cerrar la respuesta, el job la finaliza como COMPLETED; si lanza una excepcion, el job la marca FAILED.
 */
interface Respondedor
{
    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void;
}
