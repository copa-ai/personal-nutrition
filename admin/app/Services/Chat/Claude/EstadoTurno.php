<?php

namespace App\Services\Chat\Claude;

/** Lo que va pasando en un turno de chat (lo comparten el respondedor y sus callbacks). */
final class EstadoTurno
{
    public bool $escribio = false;       // ya se escribio algo (texto o razonamiento) en la respuesta

    public bool $hayTexto = false;       // hay texto de respuesta (no solo razonamiento)

    public bool $cancelado = false;      // la respuesta ya es final: hay que parar

    public bool $detenido = false;       // ya se ha pedido matar la ejecucion

    public ?string $ejecucion = null;    // id de la ejecucion en el runner remoto

    public ?int $pid = null;             // pid si claude corre en este proceso

    public ?string $final = null;        // texto del evento «result»
}
