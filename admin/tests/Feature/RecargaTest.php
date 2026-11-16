<?php

namespace Tests\Feature;

use App\Services\BaseCompartida\Recarga;
use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Tests\TestCase;

/** `Recarga` contra un `Nodo` falso: la base se mantiene con saldo, pero sin dejar sin el al servicio principal. */
class RecargaTest extends TestCase
{
    public const TICK = 1000;   // MU por tick de 10 s: 24 h = 8640 ticks, x1,25 de margen = 10,8 M MU

    /** @param  int|null  $saldoPropio  lo que contesta `celaut account` (null: falla) */
    private function nodo(int $saldoBase, ?int $saldoPropio, bool $acepta = true): object
    {
        return new class($saldoBase, $saldoPropio, $acepta) extends Nodo
        {
            /** @var list<int> */
            public array $depositos = [];

            public function __construct(private int $base, private ?int $propio, private bool $acepta) {}

            public function idDeDependencia(string $nombre): ?string
            {
                return 'abc';
            }

            public function coste(string $dependencia): array
            {
                return ['init_maintenance_cost_mu' => (string) RecargaTest::TICK];
            }

            public function metricas(int $lease): array
            {
                return ['balance_mu' => (string) ($this->base + array_sum($this->depositos)), 'suspended' => false];
            }

            public function cuenta(int $presupuestoMu = 10000): array
            {
                if ($this->propio === null) {
                    throw new NodoException('celaut account: sin respuesta', true);
                }

                return ['balance_mu' => (string) ($this->propio - array_sum($this->depositos))];
            }

            public function depositar(int $lease, string $mu): array
            {
                if (! $this->acepta || ($this->propio !== null && (int) $mu > $this->propio - array_sum($this->depositos))) {
                    return ['success' => false, 'message' => 'saldo insuficiente'];
                }
                $this->depositos[] = (int) $mu;

                return ['success' => true, 'message' => ''];
            }
        };
    }

    private function recarga(float $reparto = 0.3): Recarga
    {
        return new Recarga(true, 24, 1.25, 10, 'nutrition-postgres', $reparto);
    }

    private function pool(): array
    {
        return [['lease' => 1, 'service' => 'abc']];
    }

    public function test_con_saldo_de_sobra_en_el_servicio_la_base_llega_al_objetivo(): void
    {
        $nodo = $this->nodo(0, 100_000_000);   // la base se lleva 10,8 M de 100 M: menos del 30 %

        $r = $this->recarga()->revisar($nodo, $this->pool())[0];

        $this->assertSame('ok', $r['estado']);
        $this->assertSame(10_800_000, $r['anadido_mu']);
        $this->assertSame([10_800_000], $nodo->depositos);
    }

    public function test_la_base_nunca_se_lleva_mas_que_su_parte_del_saldo_conjunto(): void
    {
        // El caso de la v0.9.3: 33 M de financiacion y un objetivo de 208 M. Antes la base se llevaba ~25 M y el servicio se quedaba sin saldo.
        $nodo = $this->nodo(0, 33_000_000);

        $r = $this->recarga()->revisar($nodo, $this->pool())[0];

        $this->assertSame('parcial', $r['estado']);
        $this->assertSame(9_900_000, $r['anadido_mu'], 'el 30 % de 33 M');
        $this->assertGreaterThanOrEqual(23_100_000, 33_000_000 - array_sum($nodo->depositos), 'al servicio le queda el 70 %');
    }

    public function test_con_la_base_ya_en_su_parte_no_se_anade_nada(): void
    {
        $nodo = $this->nodo(9_900_000, 23_100_000);

        $r = $this->recarga()->revisar($nodo, $this->pool())[0];

        $this->assertSame('parcial', $r['estado']);
        $this->assertSame(0, $r['anadido_mu']);
        $this->assertSame([], $nodo->depositos);
    }

    public function test_si_no_se_puede_leer_el_saldo_del_servicio_se_anade_como_mucho_una_hora(): void
    {
        $nodo = $this->nodo(0, null);

        $r = $this->recarga()->revisar($nodo, $this->pool())[0];

        $this->assertSame(360 * self::TICK, $r['anadido_mu']);
    }

    public function test_con_el_reparto_en_uno_no_hay_tope(): void
    {
        $nodo = $this->nodo(0, 100_000_000);

        $this->assertSame(10_800_000, $this->recarga(1.0)->revisar($nodo, $this->pool())[0]['anadido_mu']);
    }
}
