<?php

namespace App\Services\Celaut;

use RuntimeException;

/** Una orden de `celaut` fallo. `inalcanzable`: el nodo no contesto (no es un rechazo suyo). */
class NodoException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $inalcanzable = false)
    {
        parent::__construct($mensaje);
    }
}
