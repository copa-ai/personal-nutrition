<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ilustracion de un alimento En el SQLite del chat. */
class CardsIlustracion extends Model
{
    protected $connection = 'chat';

    protected $table = 'cards_ilustraciones';

    protected $guarded = [];

    protected $primaryKey = 'clave';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected function casts(): array
    {
        return ['intentos' => 'integer', 'ultimo_intento_ms' => 'integer'];
    }
}
