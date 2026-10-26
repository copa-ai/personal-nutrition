<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Ciclo de vida de un menú: borrador → en cola → en marcha ⇄ en espera (entre tandas) → terminado / fallido. */
enum EstadoMenu: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';
    case EnCola = 'en_cola';
    case EnMarcha = 'en_marcha';
    case EnEspera = 'en_espera';
    case Terminado = 'terminado';
    case Fallido = 'fallido';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador', self::EnCola => 'En cola', self::EnMarcha => 'En marcha', self::EnEspera => 'En espera',
            self::Terminado => 'Terminado', self::Fallido => 'Fallido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Terminado => 'success', self::EnMarcha => 'warning', self::EnEspera => 'info', self::EnCola => 'info', self::Fallido => 'danger',
            self::Borrador => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Borrador => Heroicon::OutlinedPencilSquare, self::EnCola => Heroicon::OutlinedClock,
            self::EnMarcha => Heroicon::OutlinedArrowPath, self::EnEspera => Heroicon::OutlinedPauseCircle, self::Terminado => Heroicon::OutlinedCheckCircle,
            self::Fallido => Heroicon::OutlinedExclamationTriangle,
        };
    }

    /** Esta en cola, en marcha o esperando su siguiente tanda. */
    public function enCurso(): bool
    {
        return $this === self::EnCola || $this === self::EnMarcha || $this === self::EnEspera;
    }
}
