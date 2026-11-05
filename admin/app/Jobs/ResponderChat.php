<?php

namespace App\Jobs;

use App\Models\ChatMensaje;
use App\Services\Chat\Respondedor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Genera la respuesta del servicio a un mensaje de la persona con el Respondedor enlazado. Solo lleva ids (nunca texto ni
 * secretos: la tabla jobs no debe guardarlos).
 */
class ResponderChat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;   // una respuesta de Claude puede tardar minutos

    /** Cola propia del chat: un worker aparte (docker/servicio/start.sh) para que generar un menu, que puede durar horas, no la bloquee. */
    public const COLA = 'chat';

    public function __construct(public int $preguntaId, public int $respuestaId)
    {
        $this->onQueue(self::COLA);
    }

    public function handle(Respondedor $respondedor): void
    {
        $pregunta = ChatMensaje::query()->find($this->preguntaId);
        $respuesta = ChatMensaje::query()->find($this->respuestaId);
        if (! $pregunta || ! $respuesta || $respuesta->estado->esFinal()) {
            return;   // cancelada antes de empezar
        }

        try {
            $respondedor->responder($pregunta, $respuesta);
        } catch (Throwable $e) {
            report($e);
            $respuesta->fallar('No se pudo generar la respuesta. Inténtalo de nuevo.');

            return;
        }

        $respuesta->finalizar();   // si el motor no la cerro; no hace nada si ya es final
    }

    /** Si la cola mata el job a medias, la respuesta no se queda abierta para siempre. */
    public function failed(Throwable $e): void
    {
        ChatMensaje::query()->find($this->respuestaId)?->fallar('La respuesta se interrumpió. Inténtalo de nuevo.');
    }
}
