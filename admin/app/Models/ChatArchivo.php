<?php

namespace App\Models;

use App\Models\Concerns\UuidDeClaveNatural;
use Illuminate\Database\Eloquent\Model;

/**
 * Archivo del chat (foto de una comida...) guardado en la base: hash SHA3-256 hexadecimal unico y contenido binario.
 * Con PostgreSQL el contenido (bytea) llega como un resource: usar bytes(), y no cargarlo si no hace falta
 * (ChatArchivo::query()->select(ChatArchivo::SIN_CONTENIDO)).
 */
class ChatArchivo extends Model
{
    use UuidDeClaveNatural;

    public static function claveNatural(): string
    {
        return 'hash';
    }

    /** Columnas menos el contenido, para listar sin cargar los bytes. */
    public const SIN_CONTENIDO = ['id', 'hash', 'mime', 'tamano', 'nombre', 'created_at', 'updated_at'];

    protected $table = 'chat_archivos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tamano' => 'integer'];
    }

    /** Los bytes del archivo, vengan como resource (leidos de PostgreSQL) o como string (un modelo recien creado). */
    public function bytes(): string
    {
        $valor = $this->attributes['contenido'] ?? '';
        if (is_resource($valor)) {
            rewind($valor);

            return (string) stream_get_contents($valor);
        }

        return (string) $valor;
    }

    /** Forma JSON de la API interna: {"hash","mime","tamano","nombre"}. */
    public function paraApi(): array
    {
        return ['hash' => $this->hash, 'mime' => $this->mime, 'tamano' => (int) $this->tamano, 'nombre' => $this->nombre];
    }
}
