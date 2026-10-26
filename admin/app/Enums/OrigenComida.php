<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** De dónde salió lo que se comió de verdad: una opción del menú, algo cocinado en casa sin seguir el menú, o fuera de casa. */
enum OrigenComida: string implements HasLabel
{
    case Menu = 'menu';
    case Casa = 'casa';
    case Fuera = 'fuera';

    public function getLabel(): string
    {
        return match ($this) {
            self::Menu => 'Opción del menú', self::Casa => 'Casa, sin seguir el menú', self::Fuera => 'Fuera de casa',
        };
    }
}
