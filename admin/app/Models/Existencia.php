<?php

namespace App\Models;

use App\Models\Concerns\AceptaClavesDeFormulario;
use App\Models\Existencia\SumaStock;
use Database\Factories\ExistenciaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cantidad', 'unidad', 'caduca_el'])]
class Existencia extends Model
{
    /** @use HasFactory<ExistenciaFactory> */
    use AceptaClavesDeFormulario, HasFactory, SumaStock;

    /** @var list<string> */
    protected array $clavesDeFormulario = ['hogar_id', 'alimento_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'caduca_el' => 'date'];
    }

    /** @return BelongsTo<Hogar, $this> */
    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    /** @return BelongsTo<Alimento, $this> */
    public function alimento(): BelongsTo
    {
        return $this->belongsTo(Alimento::class);
    }
}
