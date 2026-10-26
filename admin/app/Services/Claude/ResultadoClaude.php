<?php

namespace App\Services\Claude;

/** Lo que dejo una ejecucion de `claude -p`: su salida normal, sus avisos y el codigo de salida. */
final class ResultadoClaude
{
    public function __construct(
        public readonly string $salida,
        public readonly string $errores,
        public readonly ?int $codigo,
    ) {}

    public function exitoso(): bool
    {
        return $this->codigo === 0;
    }

    /** Salida y avisos juntos, para mostrar un mensaje. */
    public function texto(): string
    {
        return trim($this->salida.' '.$this->errores);
    }
}
