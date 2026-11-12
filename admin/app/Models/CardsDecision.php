<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Decision del usuario sobre una tarjeta; el historial solo crece (deshacer marca la anterior y anade otra). En el SQLite del chat. */
class CardsDecision extends Model
{
    protected $connection = 'chat';

    protected $table = 'cards_decisiones';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['undo' => 'boolean', 'deshecha' => 'boolean', 'efecto' => 'array', 'acted_ms' => 'integer', 'creado_ms' => 'integer'];
    }
}
