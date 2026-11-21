<?php

namespace Tests\Support;

use App\Services\Coder\LectorEspecificacion;

/** A spec reader that returns the tags that the test sets (null means that the spec cannot be read). */
class LectorEspecificacionFalso extends LectorEspecificacion
{
    /** @param  list<string>|null  $tags */
    public function __construct(public ?array $tags = ['project']) {}

    public function tagsInvitado(string $tipo, string $referencia): ?array
    {
        return $this->tags;
    }
}
