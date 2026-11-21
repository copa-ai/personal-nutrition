<?php

namespace App\Services\Coder;

use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\CoderEstado;
use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Funding controls for the Coder. This class moves balance (MU) from nutrition to the Coder child, and only when three controls pass.
 * The controls run in this order. The first one that fails gives the reason.
 *
 *  1. "tope": MU sent during the life of this Coder plus its current balance must not pass the total cap.
 *  2. "porcentaje": the balance of the Coder after the transfer must not pass a percentage of the joint balance (nutrition plus Coder).
 *  3. "vida": nutrition must keep at least the minimum life (hours) after the transfer. If the life is unknown, nothing is sent.
 *
 * `decidir` is the pure rule. It reads no setting and calls no node. All other methods read the node and the settings.
 * The rate of burn comes from samples of the nutrition balance (`celaut account`). Each sample also holds the total that nutrition
 * deposited in its children, so a deposit is never counted as burn. See docs/coder/decisions.md, entries 41 to 57.
 *
 * Setting keys (App\Models\Ajuste):
 *  - coder.tope_mu          total cap in MU. Empty: the default (24 hours of maintenance with a margin).
 *  - coder.porcentaje_max   percentage of the joint balance. Default config coder.saldo.porcentaje.
 *  - coder.horas_minimas    minimum life of nutrition in hours. Default config coder.saldo.horas_minimas.
 * Volatile values live in the table coder_estado (App\Models\CoderEstado), not in `ajustes`. The `ajustes` table has an audit trigger,
 * and a write every 5 minutes would fill the audit log. The keys are coder.muestras_saldo, coder.depositado_hijos_mu, coder.precio,
 * coder.ultimo_rechazo, coder.saldo_mu, coder.saldo_nutrition_mu, coder.horas_vida and coder.enviado_mu.
 *
 * A user error is a CoderException with a Spanish message.
 */
class Saldo
{
    /** Upper bound for every amount (10^15 MU). It keeps all integer arithmetic far from the PHP integer limit. */
    public const MAX_MU = 1_000_000_000_000_000;

    public const CTRL_CANTIDAD = 'cantidad';

    public const CTRL_SALDO = 'saldo';

    public const CTRL_TOPE = 'tope';

    public const CTRL_PORCENTAJE = 'porcentaje';

    public const CTRL_VIDA = 'vida';

    public const CTRL_DEPOSITO = 'deposito';

    /** A refusal of the same control is audited once in this time when the loop repeats it. */
    private const SILENCIO_S = 3600;

    public function __construct(
        private readonly Nodo $nodo,
        private readonly Coder $coder,
    ) {}

    public static function desdeConfig(Coder $coder): self
    {
        return new self(Nodo::desdeConfig(), $coder);
    }

    // --- Pure rule ---

    /**
     * Decide if an amount can go to the Coder. No side effect.
     *
     * Input keys: monto (the amount asked), saldo_coder, saldo_nutrition, enviado (MU sent in this life), tope (null: no cap known),
     * porcentaje (0 to 100), horas_minimas, muestras (burn-rate samples, see `tasa`). Amounts are integers or digit strings.
     *
     * @param  array<string, mixed>  $p
     * `maximo_mu` is the largest amount that passes all three controls (0 if none). `control` is the first control that failed.
     *
     * @return array{permitido: bool, motivo: string|null, control: string|null, maximo_mu: int, vida_horas: float|null, tasa_mu_s: float|null}
     */
    public static function decidir(array $p): array
    {
        $coder = self::aMu($p['saldo_coder'] ?? null);
        $nutrition = self::aMu($p['saldo_nutrition'] ?? null);
        $enviado = self::aMu($p['enviado'] ?? 0) ?? 0;
        $tope = self::aMu($p['tope'] ?? null);
        $porcentaje = max(0, min(100, (int) ($p['porcentaje'] ?? 0)));
        $horasMin = max(0.0, (float) ($p['horas_minimas'] ?? 0));
        $tasa = self::tasa($p['muestras'] ?? []);

        $monto = self::aMu($p['monto'] ?? null, false);
        if ($monto === null) {
            return self::resultado(false, self::CTRL_CANTIDAD, self::motivoCantidad($p['monto'] ?? null), 0, null, $tasa);
        }
        if ($coder === null || $nutrition === null) {
            return self::resultado(false, self::CTRL_SALDO, 'No se conoce el saldo del servicio o del Coder, así que no se envía nada.', 0, null, $tasa);
        }

        // Room left under each control. The largest amount that passes is the smallest room.
        $salaTope = $tope === null ? 0 : max(0, $tope - ($enviado + $coder));
        $conjunto = $nutrition + $coder;
        $limite = intdiv($conjunto, 100) * $porcentaje + intdiv(($conjunto % 100) * $porcentaje, 100);
        $salaPorcentaje = max(0, $limite - $coder);
        $necesario = $tasa === null ? null : (int) ceil($horasMin * 3600 * $tasa);   // MU that nutrition must keep
        $salaVida = $necesario === null ? 0 : max(0, $nutrition - $necesario);
        $vida = $tasa === null ? null : $nutrition / $tasa / 3600;
        $maximo = min($salaTope, $salaPorcentaje, $salaVida);

        $falla = null;
        $motivo = null;
        if ($monto <= 0) {
            [$falla, $motivo] = [self::CTRL_CANTIDAD, 'La cantidad tiene que ser mayor que 0.'];
        } elseif ($tope === null) {
            [$falla, $motivo] = [self::CTRL_TOPE, 'No hay un tope total de saldo para el Coder, así que no se envía nada.'];
        } elseif ($monto > $salaTope) {
            [$falla, $motivo] = [self::CTRL_TOPE, sprintf(
                'Se pasaría del tope total: ya se enviaron %s MU, el Coder tiene %s MU y el tope es %s MU. Caben como mucho %s MU.',
                self::f($enviado), self::f($coder), self::f($tope), self::f($salaTope),
            )];
        } elseif ($monto > $salaPorcentaje) {
            [$falla, $motivo] = [self::CTRL_PORCENTAJE, sprintf(
                'El Coder pasaría del %d %% del saldo conjunto (%s MU): caben como mucho %s MU.',
                $porcentaje, self::f($conjunto), self::f($salaPorcentaje),
            )];
        } elseif ($tasa === null) {
            [$falla, $motivo] = [self::CTRL_VIDA, 'Todavía no se sabe cuánto gasta el servicio (faltan muestras del saldo), así que no se envía nada.'];
        } elseif ($nutrition < $necesario) {
            [$falla, $motivo] = [self::CTRL_VIDA, sprintf(
                'Al servicio le quedan unas %s h de vida y el mínimo es %s h: no se envía nada.',
                self::h($vida), self::h($horasMin),
            )];
        } elseif ($monto > $salaVida) {
            [$falla, $motivo] = [self::CTRL_VIDA, sprintf(
                'El envío dejaría al servicio con menos de %s h de vida: caben como mucho %s MU.',
                self::h($horasMin), self::f($salaVida),
            )];
        }

        return self::resultado($falla === null, $falla, $motivo, $maximo, $vida, $tasa);
    }

    /**
     * Burn rate of nutrition in MU per second, from samples of its balance. Each sample is {t, saldo, dep}: unix time, balance and the
     * total that nutrition deposited in its children so far. Between two samples: burn = balance fall minus the deposits.
     * An interval with a negative burn (a refund or a credit) or no time is dropped. The rate is unknown (null) with too few
     * samples, too short a span, a dropped interval in a short list, or a rate of zero or less.
     *
     * @param  mixed  $muestras  list of samples; bad entries are ignored
     */
    public static function tasa(mixed $muestras, ?int $minimo = null, ?int $segundos = null): ?float
    {
        $minimo ??= (int) config('coder.saldo.muestras_minimas', 4);
        $segundos ??= (int) config('coder.saldo.segundos_minimos', 600);
        if (! is_array($muestras)) {
            return null;
        }
        $validas = [];
        foreach ($muestras as $m) {
            if (is_array($m) && isset($m['t'], $m['saldo']) && is_numeric($m['t'])) {
                $saldo = self::aMu($m['saldo']);
                if ($saldo !== null) {
                    $validas[] = ['t' => (int) $m['t'], 'saldo' => $saldo, 'dep' => self::aMu($m['dep'] ?? 0) ?? 0];
                }
            }
        }
        if (count($validas) < max(2, $minimo)) {
            return null;
        }
        usort($validas, fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        $quemado = 0;
        $tiempo = 0;
        $intervalos = 0;
        for ($i = 1; $i < count($validas); $i++) {
            [$a, $b] = [$validas[$i - 1], $validas[$i]];
            $dt = $b['t'] - $a['t'];
            $gasto = $a['saldo'] - $b['saldo'] - ($b['dep'] - $a['dep']);
            if ($dt <= 0 || $gasto < 0) {
                continue;
            }
            $quemado += $gasto;
            $tiempo += $dt;
            $intervalos++;
        }
        if ($intervalos < max(1, $minimo - 1) || $tiempo < $segundos || $quemado <= 0) {
            return null;
        }

        return $quemado / $tiempo;
    }

    /**
     * Read an amount of MU. Accepts a non-negative integer or a string of digits. Anything else gives null.
     * With $limitar, a value over MAX_MU is cut to MAX_MU (for balances). Without it, such a value gives null (for requests).
     */
    public static function aMu(mixed $valor, bool $limitar = true): ?int
    {
        if (is_int($valor)) {
            $texto = (string) $valor;
        } elseif (is_string($valor)) {
            $texto = trim($valor);
        } else {
            return null;
        }
        if (! preg_match('/^[0-9]{1,40}$/', $texto)) {
            return null;
        }
        $texto = ltrim($texto, '0');
        if ($texto === '') {
            return 0;
        }
        if (strlen($texto) > 16 || (int) $texto > self::MAX_MU) {
            return $limitar ? self::MAX_MU : null;
        }

        return (int) $texto;
    }

    // --- Settings ---

    /**
     * The three limits as they apply now. `tope_mu` is null while there is no setting and no known price.
     *
     * @return array{tope_mu: int|null, tope_por_defecto: bool, porcentaje: int, horas_minimas: int}
     */
    public function limites(): array
    {
        $tope = self::aMu(Ajuste::obtener('coder.tope_mu'));
        $porcentaje = self::aMu(Ajuste::obtener('coder.porcentaje_max', config('coder.saldo.porcentaje')));
        $horas = self::aMu(Ajuste::obtener('coder.horas_minimas', config('coder.saldo.horas_minimas')));

        return [
            'tope_mu' => $tope ?? $this->topePorDefecto(),
            'tope_por_defecto' => $tope === null,
            'porcentaje' => min(100, $porcentaje ?? (int) config('coder.saldo.porcentaje')),
            'horas_minimas' => min(8760, $horas ?? (int) config('coder.saldo.horas_minimas')),
        ];
    }

    /**
     * Save the three limits that the panel edits (audited by Ajuste::fijar). An empty cap means "use the default".
     *
     * @throws CoderException with a Spanish message if one value is bad. Then nothing changes.
     */
    public function guardarLimites(mixed $tope, mixed $porcentaje, mixed $horasMinimas): array
    {
        $tope = is_string($tope) ? trim($tope) : $tope;
        if ($tope === null || $tope === '') {
            $topeMu = null;
        } else {
            $topeMu = self::aMu($tope, false);
            if ($topeMu === null || $topeMu <= 0) {
                throw new CoderException('El tope total tiene que ser un número entero de MU mayor que 0, o estar vacío para usar el de por defecto.');
            }
        }
        $pct = self::aMu($porcentaje, false);
        if ($pct === null || $pct < 1 || $pct > 100) {
            throw new CoderException('El porcentaje tiene que ser un número entero entre 1 y 100.');
        }
        $horas = self::aMu($horasMinimas, false);
        if ($horas === null || $horas > 8760) {
            throw new CoderException('Las horas mínimas de vida tienen que ser un número entero entre 0 y 8760.');
        }

        Ajuste::fijar('coder.tope_mu', $topeMu === null ? null : (string) $topeMu);
        Ajuste::fijar('coder.porcentaje_max', $pct);
        Ajuste::fijar('coder.horas_minimas', $horas);

        return $this->limites();
    }

    /** Default cap: `horas_tope` hours of maintenance at the last known price, times the margin. Null with no known price. */
    private function topePorDefecto(): ?int
    {
        $precio = $this->precioGuardado();
        if ($precio === null) {
            return null;
        }
        $periodos = intdiv((int) config('coder.saldo.horas_tope') * 3600, $this->periodoS());

        return (int) min(self::MAX_MU, ceil($precio * $periodos * max(1.0, (float) config('coder.saldo.margen'))));
    }

    // --- Price of the Coder ---

    private function periodoS(): int
    {
        return max(1, (int) config('nutrition.recarga.periodo_s', 10));
    }

    /** MU for one maintenance period of the Coder at the largest resources (max_maintenance_cost_mu), as last read. */
    private function precioGuardado(): ?int
    {
        $p = CoderEstado::obtener('coder.precio');
        $mu = is_array($p) ? self::aMu($p['periodo_mu'] ?? null) : null;

        return $mu !== null && $mu > 0 ? $mu : null;
    }

    /** Read the price from the node when the stored one is missing or old. On a node error the old price stays. */
    private function actualizarPrecio(?string $servicio): ?int
    {
        $p = CoderEstado::obtener('coder.precio');
        $reciente = is_array($p) && isset($p['leido_en']) && (now()->getTimestamp() - (int) $p['leido_en']) < (int) config('coder.saldo.precio_vigente_s');
        if (! $reciente && $servicio !== null) {
            try {
                $mu = self::aMu($this->nodo->costePorHash($servicio)['max_maintenance_cost_mu'] ?? null);
                if ($mu !== null && $mu > 0) {
                    self::guardar('coder.precio', ['periodo_mu' => $mu, 'leido_en' => now()->getTimestamp()]);
                }
            } catch (NodoException) {
                // Keep the old price. The cap and the hours use it.
            }
        }

        return $this->precioGuardado();
    }

    // --- Samples ---

    /**
     * Read the balance of nutrition (and of the Coder, if it runs), store a sample and the last known balances.
     * The deposit counter is read before the balance: a deposit between the two reads can only make the burn look larger.
     *
     * @return array{nutrition: int, coder: int|null, suspendido: bool}
     *
     * @throws CoderException if the node does not answer or gives a bad balance
     */
    public function muestrear(): array
    {
        $estado = $this->coder->estado();
        $dep = self::aMu(CoderEstado::obtener('coder.depositado_hijos_mu', 0)) ?? 0;
        try {
            $nutrition = self::aMu($this->nodo->cuenta()['balance_mu'] ?? null);
            $coder = null;
            $suspendido = false;
            if ($estado['estado'] === Coder::EN_MARCHA && $estado['lease'] !== null) {
                $m = $this->nodo->metricas($estado['lease']);
                $coder = self::aMu($m['balance_mu'] ?? null);
                $suspendido = (bool) ($m['suspended'] ?? false);
                if ($coder === null) {
                    throw new CoderException('El nodo no dio el saldo del Coder.');
                }
            }
        } catch (NodoException $e) {
            throw new CoderException('No se pudo leer el saldo: '.$e->getMessage());
        }
        if ($nutrition === null) {
            throw new CoderException('El nodo no dio el saldo del servicio.');
        }

        $ahora = now()->getTimestamp();
        $muestras = array_values(array_filter(
            CoderEstado::obtener('coder.muestras_saldo', []) ?: [],
            fn (mixed $m): bool => is_array($m) && isset($m['t']) && $ahora - (int) $m['t'] <= (int) config('coder.saldo.muestras_vigencia_s'),
        ));
        $muestras[] = ['t' => $ahora, 'saldo' => $nutrition, 'dep' => $dep];
        self::guardar('coder.muestras_saldo', array_slice($muestras, -max(2, (int) config('coder.saldo.muestras_maximo'))));
        self::guardar('coder.saldo_nutrition_mu', (string) $nutrition);
        if ($coder !== null) {
            self::guardar('coder.saldo_mu', (string) $coder);
            $precio = $this->actualizarPrecio($estado['servicio']);
            self::guardar('coder.horas_vida', $precio === null ? null : round($coder / $precio * $this->periodoS() / 3600, 1));
        }

        return ['nutrition' => $nutrition, 'coder' => $coder, 'suspendido' => $suspendido];
    }

    /**
     * Run the controls for the amount that the user sets as the maximum of the launch. The Coder has no balance yet.
     * The total cap applies only when it is known (a first launch has no price yet). A refusal is audited like any other.
     * A launch with no amount lets the node fund the start with its own default (about 1 hour). This case has no control.
     *
     * @throws CoderException with the reason if a control fails or the balance is unknown
     */
    public function comprobarInicial(int $mu): void
    {
        try {
            $nutrition = self::aMu($this->nodo->cuenta()['balance_mu'] ?? null);
        } catch (NodoException $e) {
            throw new CoderException('No se pudo leer el saldo: '.$e->getMessage());
        }
        if ($nutrition === null) {
            throw new CoderException('El nodo no dio el saldo del servicio.');
        }
        $limites = $this->limites();
        $d = self::decidir([
            'monto' => $mu,
            'saldo_coder' => 0,
            'saldo_nutrition' => $nutrition,
            'enviado' => 0,
            'tope' => $limites['tope_mu'] ?? self::MAX_MU,
            'porcentaje' => $limites['porcentaje'],
            'horas_minimas' => $limites['horas_minimas'],
            'muestras' => CoderEstado::obtener('coder.muestras_saldo', []),
        ]);
        if (! $d['permitido']) {
            $this->rechazar($d, 'lanzamiento', $mu, ['nutrition' => $nutrition, 'coder' => 0, 'suspendido' => false], false);
            throw new CoderException('El máximo del arranque no pasa los límites de saldo: '.$d['motivo']);
        }
    }

    /**
     * Read the balances again and keep the other fields of the reading. No sample is stored.
     *
     * @param  array{nutrition: int, coder: int|null, suspendido: bool}  $lectura
     * @return array{nutrition: int, coder: int|null, suspendido: bool}
     *
     * @throws CoderException if the node does not answer or gives a bad balance
     */
    private function releer(array $lectura, int $lease): array
    {
        try {
            $nutrition = self::aMu($this->nodo->cuenta()['balance_mu'] ?? null);
            $coder = self::aMu($this->nodo->metricas($lease)['balance_mu'] ?? null);
        } catch (NodoException $e) {
            throw new CoderException('No se pudo leer el saldo: '.$e->getMessage());
        }
        if ($nutrition === null || $coder === null) {
            throw new CoderException('El nodo no dio el saldo.');
        }

        return [...$lectura, 'nutrition' => $nutrition, 'coder' => $coder];
    }

    /** Forget the samples. The Coder service calls this after a launch: the start charge is not burn. */
    public static function olvidarMuestras(): void
    {
        self::guardar('coder.muestras_saldo', []);
    }

    /**
     * Add to the counter of MU that nutrition deposited in its children. Every deposit must call it (App\Services\BaseCompartida\Recarga too).
     * A negative amount takes MU back from the counter (a deposit that failed).
     */
    public static function anotarDeposito(int $mu): void
    {
        $actual = self::aMu(CoderEstado::obtener('coder.depositado_hijos_mu', 0)) ?? 0;
        self::guardar('coder.depositado_hijos_mu', (string) max(0, min(self::MAX_MU * 1000, $actual + $mu)));
    }

    // --- Transfers ---

    /**
     * Send an amount the user asked for ("add balance"). It runs the controls first.
     *
     * @return array{enviado: bool, mu: int, motivo: string|null, control: string|null, maximo_mu: int}
     *
     * @throws CoderException if no Coder runs, the Coder belongs to another device, or the node does not answer
     */
    public function addBalance(mixed $monto): array
    {
        $estado = $this->coder->estado();
        if ($estado['ajeno']) {
            throw new CoderException('El Coder es de otro dispositivo que comparte esta base de datos.');
        }
        if ($estado['estado'] !== Coder::EN_MARCHA || $estado['lease'] === null) {
            throw new CoderException('No hay un Coder en marcha al que enviar saldo.');
        }

        $r = $this->bloqueado(function () use ($monto, $estado): array {
            $lectura = $this->muestrear();

            return $this->enviar($estado['lease'], $monto, $lectura, 'manual', false);
        });
        if ($r === false) {
            throw new CoderException('Hay otra operación de saldo en curso. Prueba otra vez en un momento.');
        }

        return $r;
    }

    /**
     * One funding pass for the watcher: when the Coder balance is under the threshold, send the difference to the target.
     * The amount is cut to the largest that passes the controls. Nothing is sent when a control fails.
     *
     * @param  array{nutrition: int, coder: int|null, suspendido: bool}|null  $lectura  balances that `muestrear` just read
     * `accion` is one of: ninguna, ajeno, ocupado, no_necesario, enviado, rechazado, fallo.
     *
     * @return array{accion: string, mu: int, motivo: string|null}
     */
    public function topUpToTarget(?array $lectura = null): array
    {
        $estado = $this->coder->estado();
        if ($estado['ajeno']) {
            return ['accion' => 'ajeno', 'mu' => 0, 'motivo' => null];
        }
        if ($estado['estado'] !== Coder::EN_MARCHA || $estado['lease'] === null) {
            return ['accion' => 'ninguna', 'mu' => 0, 'motivo' => null];
        }

        $r = $this->bloqueado(function () use ($lectura, $estado): array {
            try {
                // The reading of the caller can be old: a manual transfer can run before this lock. Read the balances again.
                $lectura = $lectura === null ? $this->muestrear() : $this->releer($lectura, (int) $estado['lease']);
            } catch (CoderException $e) {
                return ['accion' => 'sin_nodo', 'mu' => 0, 'motivo' => $e->getMessage()];
            }
            $precio = $this->precioGuardado() ?? $this->actualizarPrecio($estado['servicio']);
            if ($precio === null || $lectura['coder'] === null) {
                return ['accion' => 'ninguna', 'mu' => 0, 'motivo' => 'Se desconoce el precio de mantenimiento del Coder.'];
            }
            $objetivo = (int) min(self::MAX_MU, ceil($precio * intdiv((int) config('coder.saldo.objetivo_horas') * 3600, $this->periodoS())));
            if ($lectura['coder'] >= $objetivo * (float) config('coder.saldo.umbral')) {
                return ['accion' => 'no_necesario', 'mu' => 0, 'motivo' => null];
            }

            $falta = $objetivo - $lectura['coder'];
            $d = $this->decidirAhora($falta, $lectura);
            $monto = $falta;
            $minimo = $precio * intdiv(3600, $this->periodoS());   // one hour of maintenance
            if (! $d['permitido'] && $d['control'] !== self::CTRL_CANTIDAD && $d['maximo_mu'] >= min($falta, $minimo)) {
                $monto = $d['maximo_mu'];   // a smaller transfer passes all controls
            }
            $r = $this->enviar($estado['lease'], $monto, $lectura, 'objetivo', true);

            return [
                'accion' => $r['enviado'] ? 'enviado' : ($r['control'] === self::CTRL_DEPOSITO ? 'fallo' : 'rechazado'),
                'mu' => $r['mu'],
                'motivo' => $r['motivo'],
            ];
        });

        return $r === false ? ['accion' => 'ocupado', 'mu' => 0, 'motivo' => null] : $r;
    }

    /**
     * One pass of `nutrition:vigilar-coder --saldo`: sample the balance and, if the Coder runs, fund it.
     *
     * @return array{accion: string, mu: int, motivo: string|null}
     */
    public function vigilar(): array
    {
        $estado = $this->coder->estado();
        if ($estado['ajeno']) {
            return ['accion' => 'ajeno', 'mu' => 0, 'motivo' => null];
        }
        try {
            $lectura = $this->muestrear();
        } catch (CoderException $e) {
            return ['accion' => 'sin_nodo', 'mu' => 0, 'motivo' => $e->getMessage()];
        }

        return $this->topUpToTarget($lectura);
    }

    /**
     * Apply the controls to one amount with the settings and the samples of now. Then, if it passes, make the transfer.
     * The transfer is recorded BEFORE the call to the node and rolled back if the node refuses. A crash between the two steps
     * can only make the counters too high, and a counter that is too high only makes the controls stricter.
     *
     * @param  array{nutrition: int, coder: int|null, suspendido: bool}  $lectura
     * @return array{enviado: bool, mu: int, motivo: string|null, control: string|null, maximo_mu: int}
     */
    private function enviar(int $lease, mixed $monto, array $lectura, string $origen, bool $silenciar): array
    {
        $d = $this->decidirAhora($monto, $lectura);
        $mu = self::aMu($monto, false) ?? 0;
        if (! $d['permitido']) {
            $this->rechazar($d, $origen, $mu, $lectura, $silenciar);

            return ['enviado' => false, 'mu' => 0, 'motivo' => $d['motivo'], 'control' => $d['control'], 'maximo_mu' => $d['maximo_mu']];
        }

        $enviadoAntes = self::aMu(CoderEstado::obtener('coder.enviado_mu', 0)) ?? 0;
        self::guardar('coder.enviado_mu', (string) min(self::MAX_MU * 1000, $enviadoAntes + $mu));
        self::anotarDeposito($mu);
        $error = null;
        try {
            $r = $this->nodo->depositar($lease, (string) $mu);
            if (($r['success'] ?? false) !== true) {
                $error = (string) ($r['message'] ?? 'sin motivo');
            }
        } catch (NodoException $e) {
            $error = $e->getMessage();
        }
        if ($error !== null) {
            self::guardar('coder.enviado_mu', (string) $enviadoAntes);
            self::anotarDeposito(-$mu);
            $motivo = 'El nodo no aceptó el depósito: '.$error;
            self::guardar('coder.ultimo_rechazo', ['t' => now()->getTimestamp(), 'control' => self::CTRL_DEPOSITO, 'motivo' => $motivo, 'mu' => $mu, 'origen' => $origen]);
            $this->auditar('coder.saldo_fallo', 'El nodo no aceptó el saldo para el Coder', ['mu' => $mu, 'origen' => $origen, 'motivo' => $error]);

            return ['enviado' => false, 'mu' => 0, 'motivo' => $motivo, 'control' => self::CTRL_DEPOSITO, 'maximo_mu' => $d['maximo_mu']];
        }

        self::guardar('coder.saldo_mu', (string) (($lectura['coder'] ?? 0) + $mu));
        self::guardar('coder.saldo_nutrition_mu', (string) max(0, $lectura['nutrition'] - $mu));
        $this->auditar('coder.saldo_enviado', 'Saldo enviado al Coder', [
            'mu' => $mu, 'origen' => $origen, 'enviado_total_mu' => $enviadoAntes + $mu,
            'saldo_coder_antes_mu' => $lectura['coder'], 'saldo_servicio_antes_mu' => $lectura['nutrition'],
        ]);

        return ['enviado' => true, 'mu' => $mu, 'motivo' => null, 'control' => null, 'maximo_mu' => max(0, $d['maximo_mu'] - $mu)];
    }

    /**
     * @param  array{nutrition: int, coder: int|null, suspendido: bool}  $lectura
     */
    private function decidirAhora(mixed $monto, array $lectura): array
    {
        $limites = $this->limites();

        return self::decidir([
            'monto' => $monto,
            'saldo_coder' => $lectura['coder'],
            'saldo_nutrition' => $lectura['nutrition'],
            'enviado' => CoderEstado::obtener('coder.enviado_mu', 0),
            'tope' => $limites['tope_mu'],
            'porcentaje' => $limites['porcentaje'],
            'horas_minimas' => $limites['horas_minimas'],
            'muestras' => CoderEstado::obtener('coder.muestras_saldo', []),
        ]);
    }

    /**
     * Store the refusal for the panel and audit it. The loop can repeat the same refusal every pass: with $silenciar, a refusal of the
     * same control and origin is audited again only after SILENCIO_S seconds. A refusal of a user action is always audited.
     *
     * @param  array<string, mixed>  $d
     * @param  array{nutrition: int, coder: int|null, suspendido: bool}  $lectura
     */
    private function rechazar(array $d, string $origen, int $mu, array $lectura, bool $silenciar): void
    {
        $ahora = now()->getTimestamp();
        $antes = CoderEstado::obtener('coder.ultimo_rechazo');
        $repetido = $silenciar && is_array($antes) && ($antes['control'] ?? null) === $d['control'] && ($antes['origen'] ?? null) === $origen
            && ($antes['auditado_en'] ?? 0) + self::SILENCIO_S > $ahora;
        self::guardar('coder.ultimo_rechazo', [
            't' => $ahora, 'control' => $d['control'], 'motivo' => $d['motivo'], 'mu' => $mu, 'origen' => $origen,
            'auditado_en' => $repetido ? $antes['auditado_en'] : $ahora,
        ]);
        if (! $repetido) {
            $this->auditar('coder.saldo_rechazado', 'Saldo para el Coder rechazado', [
                'control' => $d['control'], 'motivo' => $d['motivo'], 'mu' => $mu, 'origen' => $origen,
                'maximo_mu' => $d['maximo_mu'], 'saldo_coder_mu' => $lectura['coder'], 'saldo_servicio_mu' => $lectura['nutrition'],
            ]);
        }
    }

    // --- Status for the panel ---

    /**
     * What the panel shows. It reads only stored values, so a page view makes no node call.
     *
     * @return array{saldo_coder_mu: int|null, saldo_nutrition_mu: int|null, horas_coder: float|null, horas_nutrition: float|null, tasa_mu_s: float|null, muestras: int, enviado_mu: int, tope_mu: int|null, tope_por_defecto: bool, porcentaje: int, horas_minimas: int, maximo_mu: int, precio_periodo_mu: int|null, ultimo_rechazo: array<string, mixed>|null}
     */
    public function status(): array
    {
        $limites = $this->limites();
        $coder = self::aMu(CoderEstado::obtener('coder.saldo_mu'));
        $nutrition = self::aMu(CoderEstado::obtener('coder.saldo_nutrition_mu'));
        $muestras = CoderEstado::obtener('coder.muestras_saldo', []) ?: [];
        $tasa = self::tasa($muestras);
        $precio = $this->precioGuardado();
        $enviado = self::aMu(CoderEstado::obtener('coder.enviado_mu', 0)) ?? 0;
        $rechazo = CoderEstado::obtener('coder.ultimo_rechazo');
        $maximo = self::decidir([
            'monto' => 1, 'saldo_coder' => $coder ?? 0, 'saldo_nutrition' => $nutrition, 'enviado' => $enviado, 'tope' => $limites['tope_mu'],
            'porcentaje' => $limites['porcentaje'], 'horas_minimas' => $limites['horas_minimas'], 'muestras' => $muestras,
        ])['maximo_mu'];

        return [
            'saldo_coder_mu' => $coder,
            'saldo_nutrition_mu' => $nutrition,
            'horas_coder' => $coder === null || $precio === null ? null : round($coder / $precio * $this->periodoS() / 3600, 1),
            'horas_nutrition' => $nutrition === null || $tasa === null ? null : round($nutrition / $tasa / 3600, 1),
            'tasa_mu_s' => $tasa,
            'muestras' => is_array($muestras) ? count($muestras) : 0,
            'enviado_mu' => $enviado,
            'tope_mu' => $limites['tope_mu'],
            'tope_por_defecto' => $limites['tope_por_defecto'],
            'porcentaje' => $limites['porcentaje'],
            'horas_minimas' => $limites['horas_minimas'],
            'maximo_mu' => $maximo,
            'precio_periodo_mu' => $precio,
            'ultimo_rechazo' => is_array($rechazo) ? $rechazo : null,
        ];
    }

    // --- Helpers ---

    /**
     * Run a callback with a lock, so the loop and the panel never move balance at the same time. Returns false if the lock is taken.
     * If the cache cannot give a lock, the callback runs without one.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|false
     */
    private function bloqueado(callable $fn): mixed
    {
        try {
            $cerrojo = Cache::lock('coder-saldo', 180);
        } catch (Throwable) {
            return $fn();
        }
        if (! $cerrojo->get()) {
            return false;
        }
        try {
            return $fn();
        } finally {
            $cerrojo->release();
        }
    }

    /** Audit without letting an audit error stop the transfer logic. */
    private function auditar(string $evento, string $descripcion, array $datos): void
    {
        try {
            Auditoria::registrar($evento, $descripcion, $datos);
        } catch (Throwable) {
        }
    }

    /** Write a volatile value in coder_estado. This table has no audit trigger. */
    private static function guardar(string $clave, mixed $valor): void
    {
        CoderEstado::fijar($clave, $valor);
    }

    /** @return array{permitido: bool, motivo: string|null, control: string|null, maximo_mu: int, vida_horas: float|null, tasa_mu_s: float|null} */
    private static function resultado(bool $ok, ?string $control, ?string $motivo, int $maximo, ?float $vida, ?float $tasa): array
    {
        return ['permitido' => $ok, 'motivo' => $motivo, 'control' => $control, 'maximo_mu' => $maximo, 'vida_horas' => $vida, 'tasa_mu_s' => $tasa];
    }

    private static function motivoCantidad(mixed $monto): string
    {
        if ((is_string($monto) && preg_match('/^\s*[0-9]{16,}\s*$/', $monto)) || (is_int($monto) && $monto > self::MAX_MU)) {
            return 'La cantidad es demasiado grande.';
        }

        return 'La cantidad no es válida: tiene que ser un número entero de MU.';
    }

    private static function f(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    private static function h(?float $horas): string
    {
        return $horas === null ? '?' : number_format($horas, 1, ',', '.');
    }
}
