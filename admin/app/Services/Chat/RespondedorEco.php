<?php

namespace App\Services\Chat;

use App\Models\ChatMensaje;

/** Responde «Eco: …» con el texto de la pregunta. Sirve de motor mientras no existan el bot ni Claude y en las pruebas. */
class RespondedorEco implements Respondedor
{
    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $texto = $pregunta->texto();
        $respuesta->anadirTexto('Eco: '.($texto !== '' ? $texto : '(sin texto)'));
        $respuesta->finalizar();
    }
}
