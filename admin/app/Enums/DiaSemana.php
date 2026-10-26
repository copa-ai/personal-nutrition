<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/** Día de la semana según ISO-8601: 1 = lunes … 7 = domingo. */
enum DiaSemana: int implements HasLabel
{
    case Lunes = 1;
    case Martes = 2;
    case Miercoles = 3;
    case Jueves = 4;
    case Viernes = 5;
    case Sabado = 6;
    case Domingo = 7;

    public static function deFecha(CarbonInterface $fecha): self
    {
        return self::from($fecha->dayOfWeekIso);
    }

    /** Letra del azulejo de cada día: L M X J V S D. */
    public function inicial(): string
    {
        return match ($this) {
            self::Lunes => 'L', self::Martes => 'M', self::Miercoles => 'X', self::Jueves => 'J',
            self::Viernes => 'V', self::Sabado => 'S', self::Domingo => 'D',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Lunes => 'Lunes', self::Martes => 'Martes', self::Miercoles => 'Miércoles', self::Jueves => 'Jueves',
            self::Viernes => 'Viernes', self::Sabado => 'Sábado', self::Domingo => 'Domingo',
        };
    }
}
