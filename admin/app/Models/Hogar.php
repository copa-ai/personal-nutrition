<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\HogarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre'])]
class Hogar extends Model
{
    /** @use HasFactory<HogarFactory> */
    use HasFactory;

    protected $table = 'hogares';

    /** @return HasMany<Perfil, $this> */
    public function perfiles(): HasMany
    {
        return $this->hasMany(Perfil::class);
    }

    /** @return HasMany<DisponibilidadDia, $this> */
    public function disponibilidad(): HasMany
    {
        return $this->hasMany(DisponibilidadDia::class);
    }

    /** @return HasMany<Existencia, $this> */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /** @return HasMany<Menu, $this> */
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    /** Minutos que el hogar tiene para cocinar «al momento» en esa fecha y momento (desayuno, almuerzo, comida, merienda o cena). Null = sin dato o ese día no se cocina. */
    public function minutosParaCocinar(CarbonInterface $fecha, string $momento): ?int
    {
        $dia = $this->disponibilidad->firstWhere('dia_semana.value', $fecha->dayOfWeekIso);

        return $dia?->{"minutos_{$momento}"};
    }
}
