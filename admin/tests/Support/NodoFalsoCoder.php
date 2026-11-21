<?php

namespace Tests\Support;

use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;

/** A Nodo that runs no process. It records the calls that the Coder service makes and answers what the test sets. */
class NodoFalsoCoder extends Nodo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $llamadas = [];

    /** @var list<array<string, mixed>> instances that `celaut list` returns */
    public array $pool = [];

    public ?string $rechazo = null;

    public bool $inalcanzable = false;

    public ?string $rechazoParada = null;

    public ?string $rechazoCoste = null;

    public string $idPaquete = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    public string $uri = '10.0.0.7:22';

    public int $lease = 5;

    /** Balance of nutrition, as `celaut account` gives it. Null: the node does not answer. */
    public ?string $saldoNutrition = '1000000';

    /** Balance of the Coder lease, as `celaut metrics` gives it. */
    public string $saldoCoder = '1000';

    public bool $suspendido = false;

    /** If set, `celaut deposit` answers success false with this message. */
    public ?string $rechazoDeposito = null;

    /** If true, `celaut deposit` throws a NodoException. */
    public bool $falloDeposito = false;

    public bool $falloMetricas = false;

    public function __construct()
    {
        parent::__construct('celaut-falso');
    }

    /** @return list<array<int, mixed>> the arguments of each call to $metodo */
    public function de(string $metodo): array
    {
        return array_values(array_map(fn (array $l): array => $l[1], array_filter($this->llamadas, fn (array $l): bool => $l[0] === $metodo)));
    }

    public function adquirirPorHash(string $hash, array $entorno = [], ?string $inicialMu = null, bool $nuevo = false): array
    {
        return $this->adquirir2('adquirirPorHash', [$hash, $entorno, $inicialMu, $nuevo]);
    }

    public function adquirirPorPaquete(string $ruta, array $entorno = [], ?string $inicialMu = null, bool $nuevo = false): array
    {
        return $this->adquirir2('adquirirPorPaquete', [$ruta, $entorno, $inicialMu, $nuevo]);
    }

    /** @param  array<int, mixed>  $args */
    private function adquirir2(string $metodo, array $args): array
    {
        $this->llamadas[] = [$metodo, $args];
        if ($this->rechazo !== null) {
            throw new NodoException($this->rechazo);
        }
        $this->pool = [['lease' => $this->lease, 'service' => 'x', 'uri' => $this->uri, 'leased' => true, 'idle_secs' => 0, 'timeouts' => 0, 'failures' => 0]];

        return ['lease' => $this->lease, 'uri' => $this->uri, 'new' => true];
    }

    public function costePorHash(string $hash, array $entorno = [], ?string $inicialMu = null): array
    {
        return $this->coste2('costePorHash', $hash);
    }

    public function costePorPaquete(string $ruta, array $entorno = [], ?string $inicialMu = null): array
    {
        return $this->coste2('costePorPaquete', $ruta);
    }

    private function coste2(string $metodo, string $ref): array
    {
        $this->llamadas[] = [$metodo, [$ref]];
        if ($this->rechazoCoste !== null) {
            throw new NodoException($this->rechazoCoste);
        }

        return ['service' => 'x', 'cost_mu' => '1000', 'init_maintenance_cost_mu' => '10', 'max_maintenance_cost_mu' => '30'];
    }

    public function idDePaquete(string $ruta): string
    {
        $this->llamadas[] = ['idDePaquete', [$ruta]];

        return $this->idPaquete;
    }

    public function instancias(): array
    {
        $this->llamadas[] = ['instancias', []];
        if ($this->inalcanzable) {
            throw new NodoException('sin respuesta', true);
        }

        return $this->pool;
    }

    public function parar(int $lease): array
    {
        $this->llamadas[] = ['parar', [$lease]];
        if ($this->rechazoParada !== null) {
            throw new NodoException($this->rechazoParada);
        }
        $this->pool = [];

        return ['refund_mu' => '77'];
    }

    public function cuenta(int $presupuestoMu = 10000): array
    {
        $this->llamadas[] = ['cuenta', []];
        if ($this->saldoNutrition === null) {
            throw new NodoException('sin respuesta', true);
        }

        return ['balance_mu' => $this->saldoNutrition];
    }

    public function metricas(int $lease): array
    {
        $this->llamadas[] = ['metricas', [$lease]];
        if ($this->falloMetricas) {
            throw new NodoException('no lease '.$lease);
        }

        return ['balance_mu' => $this->saldoCoder, 'suspended' => $this->suspendido];
    }

    public function depositar(int $lease, string $mu): array
    {
        $this->llamadas[] = ['depositar', [$lease, $mu]];
        if ($this->falloDeposito) {
            throw new NodoException('sin respuesta', true);
        }
        if ($this->rechazoDeposito !== null) {
            return ['success' => false, 'message' => $this->rechazoDeposito];
        }
        if ((int) $mu > (int) $this->saldoNutrition) {
            return ['success' => false, 'message' => 'insufficient balance'];
        }
        $this->saldoNutrition = (string) ((int) $this->saldoNutrition - (int) $mu);
        $this->saldoCoder = (string) ((int) $this->saldoCoder + (int) $mu);

        return ['success' => true, 'message' => 'ok'];
    }
}
