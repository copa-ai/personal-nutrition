<?php

namespace App\Services\Chat\Bot;

/** Lo que el bot contesta: el texto (con widgets y teclados ya compuestos), hasta 3 sugerencias y, a veces, metadatos o una petición de secreto. */
final class Respuesta
{
    /**
     * @param  list<string>  $sugerencias  hasta 3, de hasta 64 caracteres
     * @param  array<string, string>  $metadata  datos del servicio en el mensaje (p. ej. la foto pendiente)
     * @param  array{request_id: string, nombre: string, prosa: string}|null  $secreto  si va, se publica tras el texto una petición de secreto
     */
    public function __construct(
        public readonly string $texto,
        public readonly array $sugerencias = [],
        public readonly array $metadata = [],
        public readonly ?array $secreto = null,
    ) {}
}
