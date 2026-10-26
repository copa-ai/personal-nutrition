<?php

namespace App\Models;

use App\Models\Concerns\AceptaClavesDeFormulario;
use Database\Factories\CompraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fecha', 'tienda', 'notas'])]
class Compra extends Model
{
    /** @use HasFactory<CompraFactory> */
    use AceptaClavesDeFormulario, HasFactory;

    /** @var list<string> */
    protected array $clavesDeFormulario = ['hogar_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    /** @return BelongsTo<Hogar, $this> */
    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    /** @return HasMany<CompraLinea, $this> */
    public function lineas(): HasMany
    {
        return $this->hasMany(CompraLinea::class);
    }
}
