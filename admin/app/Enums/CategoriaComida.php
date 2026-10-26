<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Categoría de un alimento o de una receta (p. ej. «carne roja», «huevo»). Sirve para variedad y límites del modelo nutricional. */
enum CategoriaComida: string implements HasLabel
{
    case CarneRoja = 'carne_roja';
    case CarneBlanca = 'carne_blanca';
    case Pescado = 'pescado';
    case Marisco = 'marisco';
    case Huevo = 'huevo';
    case Lacteo = 'lacteo';
    case Legumbre = 'legumbre';
    case Cereal = 'cereal';
    case Verdura = 'verdura';
    case Fruta = 'fruta';
    case FrutosSecos = 'frutos_secos';
    case Procesado = 'procesado';
    case Dulce = 'dulce';
    case Otro = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::CarneRoja => 'Carne roja', self::CarneBlanca => 'Carne blanca', self::Pescado => 'Pescado', self::Marisco => 'Marisco',
            self::Huevo => 'Huevo', self::Lacteo => 'Lácteo', self::Legumbre => 'Legumbre', self::Cereal => 'Cereal',
            self::Verdura => 'Verdura', self::Fruta => 'Fruta', self::FrutosSecos => 'Frutos secos', self::Procesado => 'Procesado',
            self::Dulce => 'Dulce', self::Otro => 'Otro',
        };
    }
}
