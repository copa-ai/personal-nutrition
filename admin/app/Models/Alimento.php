<?php

namespace App\Models;

use App\Enums\CategoriaComida;
use Database\Factories\AlimentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'categoria', 'unidad'])]
class Alimento extends Model
{
    /** @use HasFactory<AlimentoFactory> */
    use HasFactory;

    /** Unidades admitidas en alimentos, existencias y compras. */
    public const UNIDADES = ['g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'l', 'ud' => 'unidades'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['categoria' => CategoriaComida::class];
    }
}
