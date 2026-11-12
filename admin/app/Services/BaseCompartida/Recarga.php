<?php

namespace App\Services\BaseCompartida;

use App\Models\Auditoria;
use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Throwable;

/**
 * Mantiene con saldo la base PostgreSQL que este servicio lanzo (esta en su pool de `celaut`). Una instancia solo gasta el saldo que tiene
 * y, cuando se le acaba, el nodo la para: nadie se lo repone, asi que sin esto la base muere al agotar el saldo con el que arranco.
 *
 * Cada vez que lo llama `vigilar-base` (cada 5 minutos) deja a la base con saldo para `horas` (24) de mantenimiento mas, contando el tick de
 * mantenimiento a precio de ahora (`celaut cost`: un tick = `periodo_s` segundos) con un margen por si el precio sube. Lo que anade sale del
 * saldo de este servicio. Si el saldo del servicio no alcanza, anade lo que pueda: prueba con la cantidad entera y luego con la mitad, la
 * cuarta parte... (hasta un minuto de mantenimiento) y se queda con la primera que el nodo acepte. Ver config nutrition.recarga.
 *
 * Solo toca instancias del pool que son de la dependencia de la base (otras, si las hubiera, no se tocan).
 */
class Recarga
{
    public function __construct(
        private readonly bool $activa,
        private readonly int $horas,
        private readonly float $margen,
        private readonly int $periodoS,
        private readonly string $dependencia,
    ) {}

    public static function desdeConfig(): self
    {
        $c = config('nutrition.recarga');

        return new self((bool) $c['activa'], (int) $c['horas'], (float) $c['margen'], (int) $c['periodo_s'], (string) config('nutrition.red.dependencia'));
    }

    /**
     * @param  list<array<string, mixed>>  $pool  las instancias del pool (Nodo::instancias)
     * @return list<array{lease: int, estado: string, balance_mu: int, objetivo_mu: int, anadido_mu: int, horas_restantes: float}>
     *                                                                                   estado: ok (cubre el objetivo), parcial (se anadio menos de lo que faltaba),
     *                                                                                   sin_saldo (no se pudo anadir nada) o suspendida
     *
     * @throws NodoException si el nodo no contesta
     */
    public function revisar(Nodo $nodo, array $pool): array
    {
        if (! $this->activa) {
            return [];
        }
        $id = $nodo->idDeDependencia($this->dependencia);
        $propias = $id === null ? [] : array_values(array_filter($pool, fn (array $i): bool => ($i['service'] ?? null) === $id));
        if ($propias === []) {
            return [];
        }

        $tick = (int) ($nodo->coste($this->dependencia)['init_maintenance_cost_mu'] ?? 0);
        if ($tick <= 0) {
            return [];
        }
        $ticks = intdiv($this->horas * 3600, max(1, $this->periodoS));
        $objetivo = (int) ceil($tick * $ticks * max(1.0, $this->margen));

        $resultados = [];
        foreach ($propias as $instancia) {
            $resultados[] = $this->recargar($nodo, (int) $instancia['lease'], $tick, $objetivo);
        }

        return $resultados;
    }

    /** @return array{lease: int, estado: string, balance_mu: int, objetivo_mu: int, anadido_mu: int, horas_restantes: float} */
    private function recargar(Nodo $nodo, int $lease, int $tick, int $objetivo): array
    {
        $metricas = $nodo->metricas($lease);
        $saldo = (int) ($metricas['balance_mu'] ?? 0);
        $falta = $objetivo - $saldo;
        $anadido = 0;
        $estado = ($metricas['suspended'] ?? false) ? 'suspendida' : 'ok';

        if ($falta > 0) {
            $estado = 'sin_saldo';
            foreach ($this->intentos($falta, $tick) as $cantidad) {
                try {
                    $respuesta = $nodo->depositar($lease, (string) $cantidad);
                } catch (NodoException) {
                    continue;
                }
                if (($respuesta['success'] ?? false) === true) {
                    $anadido = $cantidad;
                    $estado = $cantidad >= $falta ? 'ok' : 'parcial';

                    break;
                }
            }
        }

        $resultado = [
            'lease' => $lease, 'estado' => $estado, 'balance_mu' => $saldo, 'objetivo_mu' => $objetivo, 'anadido_mu' => $anadido,
            'horas_restantes' => round(($saldo + $anadido) / $tick * $this->periodoS / 3600, 1),
        ];
        $this->auditar($resultado, $tick);

        return $resultado;
    }

    /**
     * Cantidades a probar, de mayor a menor: lo que falta y despues su mitad, su cuarta parte... hasta un minuto de mantenimiento (como
     * mucho 12 intentos), asi se anade lo que quepa en el saldo del servicio. Cada una al menos un tick.
     *
     * @return list<int>
     */
    private function intentos(int $falta, int $tick): array
    {
        $minimo = max($tick, $tick * intdiv(60, max(1, $this->periodoS)));
        $cantidades = [];
        for ($c = $falta, $n = 0; $c >= $minimo && $n < 12; $c = intdiv($c, 2), $n++) {
            $cantidades[] = $c;
        }

        return $cantidades === [] ? [max($tick, $falta)] : $cantidades;
    }

    /** Solo se audita lo que importa (un fallo, un deposito parcial o el primero grande): cada 5 minutos entra un tick y seria ruido. */
    private function auditar(array $r, int $tick): void
    {
        $importa = $r['estado'] !== 'ok' || $r['anadido_mu'] > $tick * intdiv(3600, max(1, $this->periodoS));
        if (! $importa) {
            return;
        }
        try {
            Auditoria::escribir('base.recarga', 'Saldo de la base compartida', $r);
        } catch (Throwable) {
        }
    }
}
