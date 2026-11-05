<?php

namespace App\Services\Chat\Claude;

use App\Models\ChatMensaje;
use App\Services\Chat\Marcado\Validador;
use App\Services\Chat\Respondedor;
use App\Services\Claude\EjecutorClaude;
use App\Services\Claude\LimiteUso;
use App\Services\Claude\ResultadoClaude;
use RuntimeException;

/**
 * Modo Claude del chat. Por cada pregunta: instantanea de solo lectura (InstantaneaDatos) -> UN turno de `claude -p` con streaming
 * (EjecutorClaude) -> lo que va diciendo se vuelca en la respuesta en tiempo real -> acciones propuestas, validadas y aplicadas por
 * Laravel (AccionesChat) -> resumen de lo aplicado o rechazado al final del mensaje -> COMPLETED.
 *
 * El texto del turno va a la respuesta (saneado con el Validador de Marcado); el razonamiento (bloques `thinking` y el
 * texto que Claude dice mientras usa herramientas) va a `reasoning`. El agente nunca escribe en la base: solo lee archivos y propone acciones.
 *
 * Agente ocupado (el runner rechaza con 429 por AGENTE_MAX_SIMULTANEAS, que tambien usa la generacion de menus): la respuesta queda
 * PENDING con metadata.progreso y se reintenta cada pocos segundos hasta un tope; pasado el tope, FAILED con un mensaje claro.
 * Limite de uso del plan: no es un fallo; se dice en el mensaje cuando se renueva y se termina (no se reintenta solo: la persona espera).
 * Cancelacion: cuando una escritura devuelve false (la respuesta ya es final) se mata la ejecucion (DELETE al runner, o SIGTERM si es local).
 */
class RespondedorClaude implements Respondedor
{
    /** Marca fija con la que empieza el prompt del turno; agente/CLAUDE.md la usa para distinguir el chat de un encargo. */
    public const MARCA = 'Turno de chat';

    public function __construct(private readonly InstantaneaDatos $instantanea, private readonly AccionesChat $acciones) {}

    public static function prompt(int $turno): string
    {
        return self::MARCA." {$turno}. Usa la skill chat-nutricion: la instantánea de solo lectura está en encargos/chat-{$turno}/ (empieza por LEEME.json; "
            .'la pregunta es el último mensaje de la persona en conversacion.json). Responde en esta salida, que es lo que lee la persona en el chat. '
            ."Si hay cambios que proponer, escribe workspace/chat-acciones-{$turno}.json; no hagas preguntas bloqueantes.";
    }

    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $turno = $respuesta->id;
        try {
            $estado = new EstadoTurno;
            $this->instantanea->exportar($pregunta, $respuesta);
            AccionesChat::borrarArchivo($turno);   // por si quedo uno de un intento anterior

            try {
                $resultado = $this->lanzar($respuesta, $estado);
            } catch (RuntimeException $e) {
                // No se pudo hablar con el agente. Si aun no se escribio nada, se relanza: RespondedorMixto responde con el bot basico.
                // Si ya habia respuesta en marcha, o se paso del tiempo, la respuesta falla aqui con un error corto.
                if (! $estado->escribio && ! str_contains($e->getMessage(), 'tiempo')) {
                    throw $e;
                }
                report($e);
                $respuesta->fallar('No se pudo completar la respuesta (el asistente tardó demasiado o dejó de responder). Inténtalo de nuevo.');

                return;
            }
            if ($resultado === null || $estado->cancelado || $respuesta->cancelada()) {
                return;   // cancelada (o ya cerrada con su mensaje): no se aplica nada
            }

            $this->cerrar($respuesta, $estado, $resultado, $turno);
        } finally {
            InstantaneaDatos::limpiar($turno);
            AccionesChat::borrarArchivo($turno);
        }
    }

    /** Lanza el turno, esperando si el agente esta ocupado. Null si la respuesta se cerro mientras tanto (cancelada, o se agoto la espera). */
    private function lanzar(ChatMensaje $respuesta, EstadoTurno $estado): ?ResultadoClaude
    {
        $hasta = microtime(true) + max(0, (int) config('chat_claude.espera_agente_max_s'));
        $cada = max(0, (int) config('chat_claude.espera_agente_cada_s'));
        $avisado = false;
        while (true) {
            try {
                return $this->ejecutar($respuesta, $estado);
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), '(429)') || $estado->escribio) {
                    throw $e;
                }
                if ($respuesta->cancelada()) {
                    $estado->cancelado = true;

                    return null;
                }
                if (microtime(true) >= $hasta) {
                    $respuesta->fallar('El asistente está ocupado con otra tarea. Inténtalo de nuevo en unos minutos.');

                    return null;
                }
                if (! $avisado) {
                    $avisado = true;
                    $this->progreso($respuesta, 'Esperando a que el asistente quede libre (está generando un menú).');
                }
                $cada > 0 && sleep($cada);
            }
        }
    }

    private function ejecutar(ChatMensaje $respuesta, EstadoTurno $estado): ResultadoClaude
    {
        $parser = new LectorEventos(fn (string $tipo, string $texto): bool => $this->escribir($respuesta, $estado, $tipo, $texto));
        $ultimaCancelacion = 0.0;
        $comprobarCancelacion = function () use ($respuesta, $estado, &$ultimaCancelacion): void {
            if (! $estado->cancelado && microtime(true) - $ultimaCancelacion >= 1) {   // por si la persona cancela mientras el agente solo lee
                $ultimaCancelacion = microtime(true);
                $respuesta->cancelada() && $this->detener($estado);
            }
        };
        $r = (new EjecutorClaude)->ejecutar(
            self::prompt($respuesta->id), config('chat_claude.herramientas'), (float) config('chat_claude.timeout'),
            $comprobarCancelacion,
            fn (?int $pid) => $estado->pid = $pid,
            null,
            stream: true,
            alAlta: fn (string $id) => $estado->ejecucion = $id,
            crudo: function (string $trozo) use ($parser, $estado): void {
                $parser->alimentar($trozo);
                $estado->cancelado && $this->detener($estado);
            },
            latido: $comprobarCancelacion,   // el runner solo devuelve salida cuando el agente la produce: sin esto, cancelar en silencio no se notaba
        );
        $parser->terminar();
        $estado->final = $parser->resultado();
        $estado->hayTexto = $parser->hayTexto();

        return $r;
    }

    /** Vuelca un trozo del agente en la respuesta. Devuelve false si la respuesta ya es final (hay que parar). */
    private function escribir(ChatMensaje $respuesta, EstadoTurno $estado, string $tipo, string $texto): bool
    {
        if ($estado->cancelado) {
            return false;
        }
        if ($tipo === 'texto') {
            $separador = (string) (preg_match('/^\n+/', $texto, $m) ? $m[0] : '');
            $limpio = $this->sanear(substr($texto, strlen($separador)));
            if (trim($limpio) === '') {
                return true;
            }
            $texto = $separador.$limpio;
            $ok = $respuesta->anadirTexto($texto);
        } else {
            $ok = $respuesta->anadirRazonamiento($texto);
        }
        if ($ok && ! $estado->escribio) {
            $estado->escribio = true;
            $this->progreso($respuesta, null);
        }
        if (! $ok) {
            $estado->cancelado = true;
            $this->detener($estado);
        }

        return $ok;
    }

    private function detener(EstadoTurno $estado): void
    {
        $estado->cancelado = true;
        if ($estado->detenido) {
            return;
        }
        $estado->detenido = true;
        if ($estado->ejecucion !== null && $estado->ejecucion !== '') {
            EjecutorClaude::cancelar($estado->ejecucion);
        }
        if ($estado->pid !== null && $estado->pid > 0 && function_exists('posix_kill')) {
            posix_kill($estado->pid, 15);
        }
    }

    /** Sanea los bloques html y keyboard con el Validador de Marcado (lo que escribe un modelo no se fia: se limpia o se degrada a codigo). */
    protected function sanear(string $texto): string
    {
        return Validador::sanear($texto);
    }

    private function cerrar(ChatMensaje $respuesta, EstadoTurno $estado, ResultadoClaude $r, int $turno): void
    {
        $texto = $r->texto();
        if (! $estado->hayTexto && ($renovacion = LimiteUso::renovacion($texto.' '.(string) $estado->final)) !== null) {
            $respuesta->anadirTexto('Se ha agotado el límite de uso de Claude de este plan. Se renueva sobre las '.$renovacion->format('H:i')
                .'; vuelve a preguntarme a partir de entonces.');
            $respuesta->finalizar();

            return;
        }
        if ($r->codigo !== 0) {
            $respuesta->fallar('No se pudo generar la respuesta (el asistente terminó con un error). Inténtalo de nuevo.');

            return;
        }
        if (! $estado->hayTexto && trim((string) $estado->final) !== '') {
            $respuesta->anadirTexto($this->sanear(trim((string) $estado->final)));   // el agente no dejo texto por el camino: se usa su respuesta final
            $estado->hayTexto = true;
        }

        $resumen = '';
        if (($json = AccionesChat::leerArchivo($turno)) !== null) {
            $resumen = AccionesChat::resumen($this->acciones->procesar($json));
        }
        if (! $estado->hayTexto && $resumen === '') {
            $respuesta->fallar('El asistente no ha dado ninguna respuesta. Inténtalo de nuevo.');

            return;
        }
        $resumen !== '' && $respuesta->anadirTexto($estado->hayTexto ? $resumen : ltrim($resumen));
        $respuesta->finalizar();
    }

    private function progreso(ChatMensaje $respuesta, ?string $texto): void
    {
        $fresco = ChatMensaje::query()->whereKey($respuesta->getKey())->first();
        if (! $fresco || $fresco->estado->esFinal()) {
            return;
        }
        $metadata = $fresco->metadata ?? [];
        if ($texto === null) {
            if (! array_key_exists('progreso', $metadata)) {
                return;
            }
            unset($metadata['progreso']);
        } else {
            $metadata['progreso'] = $texto;
        }
        $fresco->metadata = $metadata === [] ? null : $metadata;
        $fresco->save();
        $respuesta->setRawAttributes($fresco->getAttributes(), true);
    }
}
