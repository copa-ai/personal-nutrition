<?php

namespace App\Models;

use App\Models\Concerns\UuidDeClaveNatural;
use Illuminate\Database\Eloquent\Model;

/** Un archivo del agente (ver App\Services\Agente\AlmacenAgente). `contenido` es base64. */
class AgenteArchivo extends Model
{
    use UuidDeClaveNatural;

    public static function claveNatural(): string
    {
        return 'ruta';
    }

    protected $table = 'agente_archivos';

    protected $fillable = ['ruta', 'contenido'];
}
