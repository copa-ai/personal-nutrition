<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un archivo del agente (ver App\Services\Agente\AlmacenAgente). `contenido` es base64. */
class AgenteArchivo extends Model
{
    protected $table = 'agente_archivos';

    protected $fillable = ['ruta', 'contenido'];
}
