<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Mazo actual de la lista de la compra (una sola fila) En el SQLite del chat. */
class CardsMazo extends Model
{
    protected $connection = 'chat';

    protected $table = 'cards_mazos';

    protected $guarded = [];

    protected $primaryKey = 'deck_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected function casts(): array
    {
        return ['tarjetas' => 'array', 'creado_ms' => 'integer', 'actualizado_ms' => 'integer'];
    }
}
