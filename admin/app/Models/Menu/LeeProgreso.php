<?php

namespace App\Models\Menu;

use App\Enums\EstadoMenu;

trait LeeProgreso
{
    /** Fases que el agente marca con `consumos.py fase …`, en orden, con su etiqueta y el avance que representan. */
    private const FASES = [
        'lectura' => ['Leyendo el encargo', 15],
        'planificacion' => ['Planificando el menú', 45],
        'validacion' => ['Validando el menú', 80],
        'terminada' => ['Entregando el resultado', 95],
    ];

    /** Última fase que el agente ha marcado, leída del final de su registro. Null si aún no ha marcado ninguna. */
    public function faseActual(): ?string
    {
        if (! $this->archivo_log || ! is_file($this->archivo_log)) {
            return null;
        }
        $f = fopen($this->archivo_log, 'rb');
        fseek($f, max(0, filesize($this->archivo_log) - 60000));
        $cola = (string) stream_get_contents($f);
        fclose($f);

        return preg_match_all('/consumos\.py\s+fase\s+(\w+)/', $cola, $m) ? end($m[1]) : null;
    }

    /** Etiqueta de lo que está haciendo el agente ahora (para la barra de progreso). */
    public function etiquetaProgreso(): string
    {
        return match (true) {
            $this->estado === EstadoMenu::Terminado => 'Menú listo',
            $this->estado === EstadoMenu::EnCola => 'En cola',
            $this->estado === EstadoMenu::EnEspera => 'Esperando a que se renueve el límite de uso',
            default => self::FASES[$this->faseActual()][0] ?? 'Arrancando',
        };
    }

    /** Avance aproximado, 0-100. Solo es una estimación a partir de las fases del agente. */
    public function progreso(): int
    {
        return match ($this->estado) {
            EstadoMenu::Terminado => 100,
            EstadoMenu::Borrador, EstadoMenu::EnCola => 0,
            default => self::FASES[$this->faseActual()][1] ?? 5,
        };
    }

    /** Días con menú en el resultado importado. */
    public function diasConMenu(): int
    {
        return count($this->contenido['dias'] ?? []);
    }

    /** Opciones de receta del resultado importado (comida y cena de todos los días). */
    public function totalOpciones(): int
    {
        return collect($this->contenido['dias'] ?? [])->sum(fn (array $d): int => count($d['comida']['opciones'] ?? []) + count($d['cena']['opciones'] ?? []));
    }
}
