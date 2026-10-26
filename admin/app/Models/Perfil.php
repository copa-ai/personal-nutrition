<?php

namespace App\Models;

use App\Models\Concerns\AceptaClavesDeFormulario;
use Database\Factories\PerfilFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo', 'objetivo', 'kcal_diarias', 'proteina_pct', 'carbohidratos_pct', 'grasa_pct', 'restricciones', 'notas'])]
class Perfil extends Model
{
    /** @use HasFactory<PerfilFactory> */
    use AceptaClavesDeFormulario, HasFactory;

    /** @var list<string> */
    protected array $clavesDeFormulario = ['hogar_id'];

    protected $table = 'perfiles';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean', 'restricciones' => 'array'];
    }

    /** @return BelongsTo<Hogar, $this> */
    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    /** @return HasMany<Feedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /** @return HasMany<RegistroComida, $this> */
    public function registrosComida(): HasMany
    {
        return $this->hasMany(RegistroComida::class);
    }

    /** @param  Builder<Perfil>  $consulta */
    public function scopeActivos(Builder $consulta): void
    {
        $consulta->where('activo', true);
    }
}
