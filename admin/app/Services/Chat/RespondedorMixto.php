<?php

namespace App\Services\Chat;

use App\Models\ChatMensaje;
use App\Services\Menu\TokenClaude;
use Throwable;

/**
 * El respondedor que enlaza el contenedor. Modo Claude si hay token de Claude y existe la clase del motor (config
 * chat.respondedor_claude); si no, el bot basico (chat.respondedor_bot); y si tampoco existe, el eco. Si Claude falla antes de
 * escribir nada, responde el bot en su lugar.
 */
class RespondedorMixto implements Respondedor
{
    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $claude = (string) config('chat.respondedor_claude');
        if (TokenClaude::existe() && class_exists($claude)) {
            try {
                $this->motor($claude)->responder($pregunta, $respuesta);

                return;
            } catch (Throwable $e) {
                $respuesta->refresh();
                if ($respuesta->estado->esFinal() || $respuesta->contenido !== [] || (string) $respuesta->razonamiento !== '') {
                    throw $e;
                }
                report($e);
            }
        }

        $this->motor($this->alternativo())->responder($pregunta, $respuesta);
    }

    private function alternativo(): string
    {
        $bot = (string) config('chat.respondedor_bot');

        return class_exists($bot) ? $bot : RespondedorEco::class;
    }

    private function motor(string $clase): Respondedor
    {
        $motor = app($clase);
        if (! $motor instanceof Respondedor) {
            throw new \LogicException($clase.' no implementa '.Respondedor::class.'.');
        }

        return $motor;
    }
}
