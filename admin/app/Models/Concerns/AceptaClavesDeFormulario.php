<?php

namespace App\Models\Concerns;

/**
 * Los modelos no ponen claves foráneas en `#[Fillable]`, pero los formularios de Filament las rellenan con `fill()`
 * (un `Select` de `hogar_id`, las filas de un `Repeater`…) y sin esto se descartarían en silencio.
 * El modelo lista en `$clavesDeFormulario` SOLO las que acepta así; cualquier otra clave foránea sigue sin ser asignable en masa.
 *
 * @property list<string> $clavesDeFormulario
 */
trait AceptaClavesDeFormulario
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        $claves = array_intersect_key($attributes, array_flip($this->clavesDeFormulario ?? []));
        parent::fill(array_diff_key($attributes, $claves));
        foreach ($claves as $clave => $valor) {
            $this->setAttribute($clave, $valor);
        }

        return $this;
    }
}
