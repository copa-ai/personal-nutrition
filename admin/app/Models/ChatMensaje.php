<?php

namespace App\Models;

use App\Enums\EstadoChat;
use App\Services\Chat\Conversacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje de la conversacion (de la persona o del servicio). Fuente de verdad: chat.md §5.
 *
 * Partes de `contenido` (formas de docs/chat-api-interna.md): {"tipo":"texto","texto"}, {"tipo":"imagen","hash","mime","tamano",
 * "nombre","alt"}, {"tipo":"audio",...}, {"tipo":"archivo",...}, {"tipo":"peticion_secreto","request_id","nombre","prosa"}, {"tipo":"slot_link","port","label"}.
 *
 * API de escritura para los motores de respuesta (bot, Claude, eco). Todas operan sobre la fila bloqueada y dejan este modelo
 * al dia; las que escriben en un mensaje abierto devuelven false si ya es final (por ejemplo, cancelado por la persona):
 * en ese caso el motor debe parar. Cada llamada es una transaccion, asi que conviene agrupar el texto en trozos, no por token.
 *
 *  - anadirTexto(): texto al final de la ultima parte de texto (PENDING pasa a IN_PROGRESS). Los widgets (bloques html) y los
 *    formularios (bloques keyboard) son texto dentro de una parte `texto`.
 *  - anadirRazonamiento(): razonamiento del modelo, aparte del contenido.
 *  - finalizar(): COMPLETED, con hasta 3 sugerencias. fallar(): FAILED con un error legible. cancelar(): CANCELLED.
 *  - enlazarSlot(): como pedirSecreto, con un texto y un enlace a otro slot (chat.md §5.8).
 *  - pedirSecreto(), anadirImagen(), anadirArchivo(): chat.md §5.2 prohibe cambiar partes que no son de texto en un mensaje
 *    abierto, asi que cada una publica un MENSAJE NUEVO ya COMPLETED (con el mismo reply_to) y lo devuelve.
 *  - cancelada(): lee de la base si la persona cancelo la respuesta.
 *
 * @property string $id
 * @property string $message_id
 * @property int $sequence
 * @property array<int, array<string, mixed>> $contenido
 * @property EstadoChat $estado
 * @property string $razonamiento
 * @property string|null $error
 * @property string|null $reply_to
 * @property bool $pinned
 * @property array<string, string>|null $metadata
 * @property bool $from_service
 * @property string|null $idempotency_key
 * @property array<int, string>|null $sugerencias
 * @property array<string, mixed>|null $corrector
 * @property int $created_ms
 * @property int $updated_ms
 */
class ChatMensaje extends Model
{
    use HasUuids;

    protected $connection = 'chat';

    protected $table = 'chat_mensajes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'contenido' => 'array',
            'estado' => EstadoChat::class,
            'pinned' => 'boolean',
            'from_service' => 'boolean',
            'metadata' => 'array',
            'sugerencias' => 'array',
            'corrector' => 'array',
            'created_ms' => 'integer',
            'updated_ms' => 'integer',
        ];
    }

    /** El texto del mensaje: la union de sus partes `texto`, sin separador. */
    public function texto(): string
    {
        $texto = '';
        foreach ($this->contenido ?? [] as $parte) {
            if (($parte['tipo'] ?? null) === 'texto') {
                $texto .= (string) ($parte['texto'] ?? '');
            }
        }

        return $texto;
    }

    /** Partes de imagen del mensaje (para que un motor lea las fotos de la persona). @return array<int, array<string, mixed>> */
    public function imagenes(): array
    {
        return array_values(array_filter($this->contenido ?? [], fn (array $p): bool => ($p['tipo'] ?? null) === 'imagen'));
    }

    /** Forma JSON del Mensaje en la API interna (docs/chat-api-interna.md). @return array<string, mixed> */
    public function paraApi(): array
    {
        $estado = $this->estado;

        return [
            'message_id' => $this->message_id,
            'sequence' => (int) $this->sequence,
            'conversation_id' => null,
            'content' => $this->contenido ?? [],
            'status' => $estado->value,
            'created_ms' => (int) $this->created_ms,
            'updated_ms' => (int) $this->updated_ms,
            'reply_to' => $this->reply_to,
            'pinned' => (bool) $this->pinned,
            'metadata' => (object) ($this->metadata ?? []),
            'from_service' => (bool) $this->from_service,
            'reasoning' => (string) $this->razonamiento,
            'idempotency_key' => $this->idempotency_key,
            'error' => $estado === EstadoChat::Fallido ? $this->error : null,
            'suggestions' => $estado === EstadoChat::Completado ? array_values($this->sugerencias ?? []) : [],
            'corrector' => $this->corrector,
        ];
    }

    private function conversacion(): Conversacion
    {
        return app(Conversacion::class);
    }

    /** Pasa PENDING a IN_PROGRESS. */
    public function iniciar(): bool
    {
        return $this->conversacion()->iniciar($this);
    }

    public function anadirTexto(string $texto): bool
    {
        return $this->conversacion()->anadirTexto($this, $texto);
    }

    public function anadirRazonamiento(string $texto): bool
    {
        return $this->conversacion()->anadirRazonamiento($this, $texto);
    }

    /** @param  array<int, string>  $sugerencias  hasta 3 respuestas cortas */
    public function finalizar(array $sugerencias = []): bool
    {
        return $this->conversacion()->finalizar($this, $sugerencias);
    }

    public function fallar(string $error): bool
    {
        return $this->conversacion()->fallar($this, $error);
    }

    public function cancelar(): bool
    {
        return $this->conversacion()->cancelar($this);
    }

    /** Pide un secreto a la persona (chat.md §5.6). Devuelve el mensaje nuevo que lleva la peticion. */
    public function pedirSecreto(string $requestId, string $nombre, string $prosa): self
    {
        return $this->conversacion()->pedirSecreto($this, $requestId, $nombre, $prosa);
    }

    /** Publica un mensaje con un texto y un enlace al slot de ese puerto (chat.md §5.8) y cierra esta respuesta. Devuelve el mensaje nuevo. */
    public function enlazarSlot(string $texto, int $puerto, ?string $etiqueta = null): self
    {
        return $this->conversacion()->enlazarSlot($this, $texto, $puerto, $etiqueta);
    }

    /** Publica una imagen guardada en chat_archivos como mensaje nuevo (ver la nota de la clase). */
    public function anadirImagen(string $hash, ?string $alt = null): self
    {
        return $this->conversacion()->publicarPartes($this, [$this->conversacion()->parteArchivo('imagen', $hash, $alt)]);
    }

    /** Publica un archivo guardado en chat_archivos como mensaje nuevo (ver la nota de la clase). */
    public function anadirArchivo(string $hash): self
    {
        return $this->conversacion()->publicarPartes($this, [$this->conversacion()->parteArchivo('archivo', $hash)]);
    }

    /** True si la persona cancelo (o el mensaje ya es final), leyendo el estado actual de la base. */
    public function cancelada(): bool
    {
        $estado = static::query()->whereKey($this->getKey())->value('estado');

        return ($estado instanceof EstadoChat ? $estado : EstadoChat::tryFrom((string) $estado)) === EstadoChat::Cancelado;
    }
}
