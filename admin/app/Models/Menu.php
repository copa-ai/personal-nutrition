<?php

namespace App\Models;

use App\Enums\EstadoMenu;
use App\Models\Menu\ConstruyeDocumento;
use App\Models\Menu\ConstruyeEncargo;
use App\Models\Menu\ElijeOpciones;
use App\Models\Menu\ImportaEntrega;
use App\Models\Menu\LeeProgreso;
use App\Models\Menu\PideTarea;
use App\Models\Menu\ValidaEntrega;
use Carbon\CarbonImmutable;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Unidad de trabajo del agente. Ver database/migrations/*_create_menus_table.php. */
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use ConstruyeDocumento, ConstruyeEncargo, ElijeOpciones, HasFactory, ImportaEntrega, LeeProgreso, PideTarea, SoftDeletes, ValidaEntrega;

    protected $guarded = [];

    protected $attributes = ['estado' => 'borrador', 'tarea' => 'generar'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoMenu::class, 'encargo' => 'array', 'contenido' => 'array', 'selecciones' => 'array', 'detener_pedido' => 'boolean',
            'fecha_inicio' => 'date', 'iniciado_en' => 'datetime', 'terminado_en' => 'datetime', 'proximo_ciclo_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // El borrado lógico no dispara nullOnDelete: si era el menú activo, el hogar queda sin activo y sin cambios (los siguientes menús generados se activan solos).
        static::deleted(function (Menu $menu): void {
            Hogar::query()->where('menu_id', $menu->id)->update(['menu_id' => null, 'cambios' => null]);
        });
    }

    /** @return BelongsTo<Hogar, $this> */
    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    /** Libro de consumo: una fila por respuesta del modelo, con su modelo y su coste congelado. */
    public function consumos(): HasMany
    {
        return $this->hasMany(ConsumoMenu::class);
    }

    /**
     * El contenido tal como se sigue ahora: el de la entrega con las tomas cambiadas por intercambio encima. Los cambios
     * (`hogares.cambios`) solo cuentan si este menú es el activo del hogar. Con `$conCambios` = false devuelve el «por defecto».
     *
     * @return array<string, mixed>
     */
    public function contenidoVigente(bool $conCambios = true): array
    {
        $contenido = $this->contenido ?? [];
        $cambios = $conCambios && $this->hogar?->menu_id === $this->id ? ($this->hogar->cambios ?? []) : [];
        if ($cambios === []) {
            return $contenido;
        }
        foreach ($contenido['dias'] ?? [] as $i => $dia) {
            foreach (['comida', 'cena'] as $momento) {
                $cambio = $cambios["{$dia['fecha']}/{$momento}"] ?? null;
                if (is_array($cambio)) {
                    $contenido['dias'][$i][$momento] = $cambio;
                }
            }
        }

        return $contenido;
    }

    /** Fechas y momentos con un cambio vigente («2026-11-02/comida»). Vacío si no es el activo o no hay cambios. */
    public function tomasCambiadas(): array
    {
        return $this->hogar?->menu_id === $this->id ? array_keys($this->hogar->cambios ?? []) : [];
    }

    /**
     * «Nombre · del 2 al 8 de noviembre». El fin es fecha_inicio + dias del menú - 1; si cruza de mes se dicen los dos
     * («del 28 de octubre al 3 de noviembre»).
     */
    public function titulo(): string
    {
        $inicio = CarbonImmutable::parse($this->fecha_inicio)->locale('es');
        $fin = $inicio->addDays(max(0, (int) config('agente.menu.dias') - 1));
        $rango = $inicio->month === $fin->month && $inicio->year === $fin->year
            ? "del {$inicio->day} al {$fin->day} de {$fin->monthName}"
            : "del {$inicio->day} de {$inicio->monthName} al {$fin->day} de {$fin->monthName}";

        return "{$this->nombre} · {$rango}";
    }

    /** Hay una parada pedida y la tanda en curso aun no ha acabado. */
    public function deteniendo(): bool
    {
        return $this->detener_pedido && $this->estado->enCurso();
    }

    /** Etiqueta del estado para la cabecera (añade «deteniendo…» si procede). */
    public function etiquetaEstado(): string
    {
        return $this->deteniendo() ? $this->estado->getLabel().' · deteniendo…' : $this->estado->getLabel();
    }
}
