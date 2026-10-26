<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TipoFeedback: string implements HasColor, HasLabel
{
    case Gusta = 'gusta';
    case NoGusta = 'no_gusta';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gusta => 'Me gusta', self::NoGusta => 'No me gusta',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Gusta => 'success', self::NoGusta => 'danger',
        };
    }
}
