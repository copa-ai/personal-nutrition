<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Volatile state of the Coder funding: key and json value. The table has no audit trigger.
 * Use `Ajuste::fijar` for a setting that a person changes. Use this class for values that the code rewrites often.
 */
class CoderEstado extends Model
{
    protected $table = 'coder_estado';

    protected $primaryKey = 'clave';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['clave', 'valor'];

    protected function casts(): array
    {
        return ['valor' => 'json'];
    }

    /** The stored value, or $defecto when the key has no row. */
    public static function obtener(string $clave, mixed $defecto = null): mixed
    {
        $fila = static::query()->where('clave', $clave)->first();

        return $fila ? $fila->valor : $defecto;
    }

    /** Write the value. No audit row is made. */
    public static function fijar(string $clave, mixed $valor): void
    {
        static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
