<?php

namespace App\Models;

use App\Enums\DiaSemana;
use Database\Factories\DisponibilidadDiaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dia_semana', 'minutos_comida', 'minutos_cena'])]
class DisponibilidadDia extends Model
{
    /** @use HasFactory<DisponibilidadDiaFactory> */
    use HasFactory;

    protected $table = 'disponibilidad_dias';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['dia_semana' => DiaSemana::class, 'minutos_comida' => 'integer', 'minutos_cena' => 'integer'];
    }

    /** @return BelongsTo<Hogar, $this> */
    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }
}
