<?php

namespace App\Models;

use App\Enums\CategoriaComida;
use App\Models\Concerns\UuidDeClaveNatural;
use Database\Factories\AlimentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'categoria', 'unidad'])]
class Alimento extends Model
{
    /** @use HasFactory<AlimentoFactory> */
    use HasFactory, UuidDeClaveNatural;

    public static function claveNatural(): string
    {
        return 'nombre';
    }

    /** Unidades admitidas en alimentos, y existencias. */
    public const UNIDADES = ['g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'l', 'ud' => 'unidades'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['categoria' => CategoriaComida::class];
    }
}
