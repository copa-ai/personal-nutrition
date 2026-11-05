<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una respuesta del modelo en un menú: tokens, modelo y coste congelado al registrarla (ver la migracion). */
class ConsumoMenu extends Model
{
    use HasUuids;

    protected $table = 'consumos_menu';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['precios' => 'array', 'fecha' => 'datetime', 'coste_total' => 'float', 'coste_entrada' => 'float', 'coste_salida' => 'float',
            'coste_cache' => 'float', 'coste_web' => 'float'];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
