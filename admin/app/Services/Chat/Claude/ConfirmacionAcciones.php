<?php

namespace App\Services\Chat\Claude;

use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Services\Chat\Marcado\Mensaje;
use App\Services\Chat\Marcado\Teclado;
use App\Services\Chat\Marcado\Tecla;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Confirmacion de las acciones destructivas que propone el agente (AccionesChat las deja `pendiente`).
 *
 * Proponer: las acciones se guardan en `metadata.acciones_pendientes` del mensaje del servicio (un texto JSON: {token, estado, creada,
 * acciones}; sin tabla nueva) y al final del mensaje se pone un teclado Confirmar / Cancelar cuyas teclas dicen «Confirmar acción TOKEN»
 * y «Cancelar acción TOKEN». El token son 12 hex aleatorios que solo Laravel conoce (la instantanea del agente los oculta).
 *
 * Confirmar: la tecla envia ese texto como un mensaje normal de la persona; RespondedorMixto lo ofrece a manejar() antes de llamar al
 * agente o al bot, asi que funciona aunque Claude este apagado. El agente nunca puede confirmar: no escribe mensajes de la persona.
 * Es idempotente: el paso de `pendiente` a `confirmada`/`cancelada`/`caducada` es un cambio atomico (compara y cambia sobre el valor
 * leido), asi que confirmar dos veces no ejecuta dos veces. Una pendiente caduca a los `chat_claude.acciones.caduca_min` minutos.
 */
class ConfirmacionAcciones
{
    public const CLAVE = 'acciones_pendientes';

    private const PATRON = '/^\s*(confirmar|cancelar) acci[oó]n ([0-9a-f]{12})\s*\.?\s*$/iu';

    /** Lo que sustituye al token en lo que se ensena al agente. */
    private const PATRON_TOKEN = '/(acci[oó]n) [0-9a-f]{12}/iu';

    public function __construct(private readonly AccionesChat $acciones) {}

    /** Oculta los tokens de confirmacion de un texto (para no dejarlos en la instantanea del agente). */
    public static function ocultarTokens(string $texto): string
    {
        return (string) preg_replace(self::PATRON_TOKEN, '$1 ····', $texto);
    }

    /**
     * Guarda las acciones pendientes en el mensaje y devuelve el bloque de texto (teclado) para ponerlo al final de la respuesta.
     *
     * @param  array<int, array{tipo: string, estado: string, detalle: string, accion?: array<string, mixed>}>  $pendientes
     */
    public function proponer(ChatMensaje $respuesta, array $pendientes): string
    {
        $acciones = array_values(array_filter(array_map(fn (array $p): ?array => $p['accion'] ?? null, $pendientes)));
        if ($acciones === []) {
            return '';
        }
        $token = bin2hex(random_bytes(6));
        $fresco = ChatMensaje::query()->find($respuesta->getKey());
        if (! $fresco) {
            return '';
        }
        $metadata = $fresco->metadata ?? [];
        $metadata[self::CLAVE] = $this->codificar(['token' => $token, 'estado' => 'pendiente', 'creada' => time(), 'acciones' => $acciones]);
        $fresco->metadata = $metadata;
        $fresco->save();
        $respuesta->setRawAttributes($fresco->getAttributes(), true);

        return Mensaje::nuevo()->teclado(
            Teclado::nuevo()->parrafo('¿Lo hago?')->fila(
                Tecla::de('Confirmar', "Confirmar acción {$token}")->enviando(),
                Tecla::de('Cancelar', "Cancelar acción {$token}")->enviando(),
            )
        )->render();
    }

    /**
     * Si el mensaje de la persona es una confirmacion o una cancelacion, la atiende (y cierra la respuesta) y devuelve true.
     * Si no lo es, devuelve false y no toca nada.
     */
    public function manejar(ChatMensaje $pregunta, ChatMensaje $respuesta): bool
    {
        if ($pregunta->from_service || ! preg_match(self::PATRON, $pregunta->texto(), $m)) {
            return false;
        }
        $confirma = mb_strtolower($m[1]) === 'confirmar';
        $token = strtolower($m[2]);

        [$origen, $crudo, $datos] = $this->buscar($token);
        if ($origen === null) {
            return $this->decir($respuesta, 'No encuentro esa propuesta. Pídeme otra vez lo que quieres hacer.');
        }
        if (($datos['estado'] ?? '') !== 'pendiente') {
            return $this->decir($respuesta, match ($datos['estado'] ?? '') {
                'confirmada' => 'Esa propuesta ya la confirmaste: no la vuelvo a ejecutar.',
                'cancelada' => 'Esa propuesta la cancelaste: no se ha hecho nada.',
                'caducada' => 'Esa propuesta caducó. Pídeme otra vez lo que quieres hacer.',
                default => 'Esa propuesta ya no está pendiente.',
            });
        }

        $caducada = time() - (int) ($datos['creada'] ?? 0) > max(1, (int) config('chat_claude.acciones.caduca_min')) * 60;
        $nuevo = $caducada ? 'caducada' : ($confirma ? 'confirmada' : 'cancelada');
        if (! $this->cambiarEstado($origen, $crudo, $datos, $nuevo)) {
            return $this->decir($respuesta, 'Esa propuesta ya se estaba atendiendo: no la ejecuto dos veces.');   // otro turno ganó la carrera
        }
        if ($caducada) {
            return $this->decir($respuesta, 'Esa propuesta caducó, por seguridad no la ejecuto. Pídeme otra vez lo que quieres hacer.');
        }
        if (! $confirma) {
            Auditoria::registrar('chat.accion_cancelada', 'Acciones del chat canceladas por la persona', ['acciones' => count($datos['acciones'] ?? [])]);

            return $this->decir($respuesta, 'Vale, no hago nada: lo pendiente queda descartado.');
        }

        try {
            $resultados = $this->acciones->ejecutarConfirmadas(array_values($datos['acciones'] ?? []));
        } catch (Throwable $e) {
            report($e);

            return $this->decir($respuesta, 'No se pudo ejecutar lo confirmado y no se ha cambiado nada. Pídemelo de nuevo.');
        }

        return $this->decir($respuesta, 'Hecho, esto es lo que ha pasado:'.AccionesChat::resumen($resultados));
    }

    /**
     * El mensaje del servicio con esa propuesta, su metadata en bruto (para el cambio atomico) y los datos decodificados.
     *
     * @return array{0: ?ChatMensaje, 1: string, 2: array<string, mixed>}
     */
    private function buscar(string $token): array
    {
        $candidatos = ChatMensaje::query()->where('from_service', true)->whereRaw('CAST(metadata AS TEXT) LIKE ?', ['%'.$token.'%'])
            ->orderByDesc('sequence')->limit(5)->get();
        foreach ($candidatos as $mensaje) {
            $crudo = (string) DB::connection('chat')->table('chat_mensajes')->where('id', $mensaje->id)->value('metadata');
            $metadata = json_decode($crudo, true);
            $datos = is_array($metadata) && is_string($metadata[self::CLAVE] ?? null) ? json_decode($metadata[self::CLAVE], true) : null;
            if (is_array($datos) && hash_equals((string) ($datos['token'] ?? ''), $token)) {
                return [$mensaje, $crudo, $datos];
            }
        }

        return [null, '', []];
    }

    /**
     * Pasa la propuesta de `pendiente` a otro estado SOLO si la metadata sigue tal como se leyo (compara y cambia en una sola
     * sentencia). Las acciones se borran: ya no hacen falta y no se guardan datos de mas.
     *
     * @param  array<string, mixed>  $datos
     */
    private function cambiarEstado(ChatMensaje $origen, string $crudo, array $datos, string $estado): bool
    {
        $metadata = json_decode($crudo, true);
        $metadata[self::CLAVE] = $this->codificar(['token' => $datos['token'], 'estado' => $estado, 'creada' => $datos['creada'] ?? 0, 'resuelta' => time(), 'acciones' => []]);

        return DB::connection('chat')->table('chat_mensajes')->where('id', $origen->id)->where('metadata', $crudo)
            ->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]) === 1;
    }

    /** @param  array<string, mixed>  $datos */
    private function codificar(array $datos): string
    {
        return (string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Escribe un texto en la respuesta y la cierra. Siempre true (el mensaje de la persona estaba atendido). */
    private function decir(ChatMensaje $respuesta, string $texto): bool
    {
        if ($respuesta->anadirTexto($texto)) {
            $respuesta->finalizar();
        }

        return true;
    }
}
