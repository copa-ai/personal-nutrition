<?php

namespace App\Enums;

/** Estado de un mensaje del chat (chat.md §5.2). El valor es el nombre de la spec. */
enum EstadoChat: string
{
    case Pendiente = 'PENDING';
    case EnCurso = 'IN_PROGRESS';
    case Completado = 'COMPLETED';
    case Fallido = 'FAILED';
    case Cancelado = 'CANCELLED';

    public function esFinal(): bool
    {
        return ! $this->esAbierto();
    }

    public function esAbierto(): bool
    {
        return $this === self::Pendiente || $this === self::EnCurso;
    }
}
