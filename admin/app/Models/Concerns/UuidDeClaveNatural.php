<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Ramsey\Uuid\Uuid;

/**
 * Clave primaria UUID derivada (UUIDv5) de la clave natural unica del modelo (`nombre`, `clave`, `ruta`, `hash`).
 *
 * Dos bifurcaciones de la base que crean la misma fila por separado le dan el mismo id, asi que al fusionarlas coinciden sin
 * reasignar nada. El modelo declara `claveNatural()`: el nombre del atributo. El id no cambia si despues se edita ese atributo.
 */
trait UuidDeClaveNatural
{
    use HasUuids;

    /** Nombre del atributo cuyo valor identifica la fila (el que tiene la restriccion unica). */
    abstract public static function claveNatural(): string;

    /** El id que le toca a la fila con esa clave natural, exista o no. */
    public static function idDeClave(string $valor): string
    {
        $espacio = Uuid::uuid5(Uuid::NAMESPACE_URL, 'personal-nutrition:'.(new static)->getTable());

        return Uuid::uuid5($espacio, $valor)->toString();
    }

    public function newUniqueId(): string
    {
        $valor = $this->getAttribute(static::claveNatural());

        return is_string($valor) && $valor !== '' ? static::idDeClave($valor) : (string) Uuid::uuid7();
    }
}
