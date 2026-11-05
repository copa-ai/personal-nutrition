<?php

namespace App\Services\Chat\Claude;

/** Motivo por el que una accion del agente no es valida (lo devuelven los validadores de AccionesChat en lugar del valor). */
final class Rechazo
{
    public function __construct(public readonly string $motivo) {}
}
