<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Momento del día. El menú planifica `Comida` y `Cena`; el registro de lo comido admite también `Otro`. */
enum MomentoComida: string implements HasLabel
{
    case Comida = 'comida';
    case Cena = 'cena';
    case Otro = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Comida => 'Comida', self::Cena => 'Cena', self::Otro => 'Otro',
        };
    }
}
