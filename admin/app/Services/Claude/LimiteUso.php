<?php

namespace App\Services\Claude;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reconoce el aviso de `claude -p` cuando se agota la ventana de uso del plan («You've hit your session limit · resets 3:20pm (UTC)»)
 * y calcula cuando se renueva. No es un fallo del menú: la tanda se reintenta despues de esa hora.
 */
class LimiteUso
{
    /** @return ?CarbonImmutable null si el texto no es un aviso de limite; si lo es, cuando se renueva (o, si no se entiende la hora, dentro de $esperaPorDefectoMin) */
    public static function renovacion(string $texto, ?CarbonImmutable $ahora = null, int $esperaPorDefectoMin = 60): ?CarbonImmutable
    {
        $ahora ??= CarbonImmutable::now();
        if (! preg_match('/(hit your .{0,30}limit|usage limit reached)/iu', $texto)) {
            return null;
        }
        if (! preg_match('/resets?\s+(?:(?<mes>[A-Za-z]{3,9})\s+(?<dia>\d{1,2}),?\s+(?:at\s+)?)?(?<h>\d{1,2})(?::(?<m>\d{2}))?\s*(?<ampm>am|pm)(?:\s*\((?<zona>[^)]+)\))?/iu', $texto, $m)) {
            return $ahora->addMinutes($esperaPorDefectoMin);
        }
        try {
            $zona = ($m['zona'] ?? '') !== '' ? $m['zona'] : 'UTC';
            $hora = ((int) $m['h'] % 12) + (strtolower($m['ampm']) === 'pm' ? 12 : 0);
            $local = $ahora->setTimezone($zona);
            $fecha = $local->setTime($hora, (int) ($m['m'] ?? 0));
            if (($m['mes'] ?? '') !== '') {
                $fecha = CarbonImmutable::parse("{$m['mes']} {$m['dia']} {$hora}:".sprintf('%02d', (int) ($m['m'] ?? 0)), $zona);
            }
            while ($fecha <= $local) {
                $fecha = $fecha->addDay();
            }

            return $fecha->setTimezone($ahora->getTimezone());
        } catch (Throwable) {
            return $ahora->addMinutes($esperaPorDefectoMin);
        }
    }
}
