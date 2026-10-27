<?php

namespace App\Models;

use App\Enums\EstadoMenu;
use App\Models\Menu\ConstruyeDocumento;
use App\Models\Menu\ConstruyeEncargo;
use App\Models\Menu\ElijeOpciones;
use App\Models\Menu\ImportaEntrega;
use App\Models\Menu\LeeProgreso;
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
    use ConstruyeDocumento, ConstruyeEncargo, ElijeOpciones, HasFactory, ImportaEntrega, LeeProgreso, SoftDeletes, ValidaEntrega;

    protected $guarded = [];

    protected $attributes = ['estado' => 'borrador'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoMenu::class, 'encargo' => 'array', 'contenido' => 'array', 'selecciones' => 'array', 'detener_pedido' => 'boolean',
            'fecha_inicio' => 'date', 'iniciado_en' => 'datetime', 'terminado_en' => 'datetime', 'proximo_ciclo_en' => 'datetime',
        ];
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
