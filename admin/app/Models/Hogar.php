<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\HogarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

#[Fillable(['nombre'])]
class Hogar extends Model
{
    /** @use HasFactory<HogarFactory> */
    use HasFactory, HasUuids;

    protected $table = 'hogares';

    protected function casts(): array
    {
        return ['cambios' => 'array'];
    }

    /** Menú que el hogar sigue ahora. Null si aún no hay ninguno activo. */
    public function menuActivo(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** Fija el menú activo. Los cambios por intercambio eran del menú anterior, así que se descartan. */
    public function activar(Menu $menu): void
    {
        $this->olvidarEleccionesDeCambios();
        $this->forceFill(['menu_id' => $menu->id, 'cambios' => null])->save();
        $this->setRelation('menuActivo', $menu);
    }

    /** Quita los cambios por intercambio: vuelve a valer el menú activo tal como se generó. */
    public function descartarCambios(): void
    {
        $this->olvidarEleccionesDeCambios();
        $this->forceFill(['cambios' => null])->save();
    }

    /**
     * Las elecciones guardadas en tomas cambiadas son índices de las opciones cambiadas: al quitar los cambios apuntarían a otro plato
     * del menú por defecto. Se borran las de esas tomas en el menú que tenía los cambios (el activo hasta ahora).
     */
    private function olvidarEleccionesDeCambios(): void
    {
        if (empty($this->cambios) || $this->menu_id === null) {
            return;
        }
        $menu = Menu::query()->find($this->menu_id);
        $selecciones = Arr::except($menu?->selecciones ?? [], array_keys($this->cambios));
        if ($menu !== null && $selecciones !== ($menu->selecciones ?? [])) {
            $menu->update(['selecciones' => $selecciones ?: null]);
        }
    }

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
