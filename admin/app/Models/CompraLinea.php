<?php

namespace App\Models;

use App\Models\Concerns\AceptaClavesDeFormulario;
use Database\Factories\CompraLineaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cantidad', 'unidad', 'caduca_el'])]
class CompraLinea extends Model
{
    /** @use HasFactory<CompraLineaFactory> */
    use AceptaClavesDeFormulario, HasFactory;

    /** @var list<string> */
    protected array $clavesDeFormulario = ['alimento_id'];

    protected $table = 'compra_lineas';

    /** Al registrar una línea, su cantidad entra en el stock del hogar. Editar o borrar la línea no toca el stock: se corrige en Existencias. */
    protected static function booted(): void
    {
        static::created(function (CompraLinea $linea): void {
            Existencia::sumar($linea->compra->hogar_id, $linea->alimento_id, (float) $linea->cantidad, $linea->unidad, $linea->caduca_el);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'caduca_el' => 'date'];
    }

    /** @return BelongsTo<Compra, $this> */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /** @return BelongsTo<Alimento, $this> */
    public function alimento(): BelongsTo
    {
        return $this->belongsTo(Alimento::class);
    }
}
