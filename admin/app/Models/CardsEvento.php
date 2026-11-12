<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Evento de Watch con version (su id). La carga es el evento ya armado, en la forma JSON de docs/chat-api-interna.md. En el SQLite del chat. */
class CardsEvento extends Model
{
    protected $connection = 'chat';

    protected $table = 'cards_eventos';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['carga' => 'array', 'creado_ms' => 'integer'];
    }
}
