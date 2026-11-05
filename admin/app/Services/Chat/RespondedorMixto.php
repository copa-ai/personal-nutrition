<?php

namespace App\Services\Chat;

use App\Models\Ajuste;
use App\Models\ChatMensaje;
use App\Services\Menu\TokenClaude;
use Throwable;

/**
 * El respondedor que enlaza el contenedor. Modo Claude si el ajuste «Chat con Claude» esta activo, hay token de Claude y existe la
 * clase del motor (config chat.respondedor_claude); si no, el bot basico (chat.respondedor_bot); y si tampoco existe, el eco. Si Claude falla antes de
 * escribir nada, responde el bot en su lugar.
 */
class RespondedorMixto implements Respondedor
{
    /** Claude puede responder: interruptor activo, token puesto y motor disponible. */
    public static function claudeActivo(): bool
    {
        return Ajuste::claudeChatHabilitado() && TokenClaude::existe() && class_exists((string) config('chat.respondedor_claude'));
    }

    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $claude = (string) config('chat.respondedor_claude');
        if (static::claudeActivo()) {
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
