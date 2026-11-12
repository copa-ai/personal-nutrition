<?php

namespace App\Services\Chat\Bot;

/** Lo que el bot contesta: el texto (con widgets y teclados ya compuestos), hasta 3 sugerencias y, a veces, metadatos, una petición de secreto o un enlace a otro slot. */
final class Respuesta
{
    /**
     * @param  list<string>  $sugerencias  hasta 3, de hasta 64 caracteres
     * @param  array<string, string>  $metadata  datos del servicio en el mensaje (p. ej. la foto pendiente)
     * @param  array{request_id: string, nombre: string, prosa: string}|null  $secreto  si va, se publica tras el texto una petición de secreto
     * @param  array{puerto: int, etiqueta: string}|null  $enlace  si va, el texto sale en un mensaje nuevo con un slot_link a ese puerto (chat.md §5.8)
     */
    public function __construct(
        public readonly string $texto,
        public readonly array $sugerencias = [],
        public readonly array $metadata = [],
        public readonly ?array $secreto = null,
        public readonly ?array $enlace = null,
    ) {}
}
