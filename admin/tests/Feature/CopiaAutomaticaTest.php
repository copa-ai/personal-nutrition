<?php

namespace Tests\Feature;

use App\Services\BaseCompartida\Candidata;
use App\Services\BaseCompartida\CopiaAutomatica;
use App\Services\BaseCompartida\CopiaDeRetirada;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

/** Cuando se toma la copia de seguridad periodica de la base elegida (el volcado en si lo prueba CopiaDeRetiradaTest). */
class CopiaAutomaticaTest extends TestCase
{
    private string $estado;

    /** @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?int}> */
    private array $pedidas = [];

    private bool $falla = false;

    private CopiaAutomatica $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estado = sys_get_temp_dir().'/auto-'.uniqid().'.json';
        config(['nutrition.copias_automaticas' => ['activa' => true, 'carpeta' => '/copias', 'intervalo_horas' => 6, 'guardadas' => 8]]);
        $prueba = $this;
        $this->servicio = new CopiaAutomatica(new class($prueba) extends CopiaDeRetirada
        {
            public function __construct(private readonly CopiaAutomaticaTest $prueba) {}

            public function guardar(string $direccion, string $identificador, string $prefijo = 'retirada', ?string $carpeta = null, ?int $guardadas = null): string
            {
                return $this->prueba->pedir($direccion, $identificador, $prefijo, $carpeta, $guardadas);
            }
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->estado.'.copias');
        parent::tearDown();
    }

    public function pedir(string $direccion, string $identificador, string $prefijo, ?string $carpeta, ?int $guardadas): string
    {
        if ($this->falla) {
            throw new RuntimeException('disco lleno');
        }
        $this->pedidas[] = [$direccion, $identificador, $prefijo, $carpeta, $guardadas];

        return "/copias/{$prefijo}-{$identificador}.sql.gz";
    }

    private function base(int $eventos, string $id = '111'): Candidata
    {
        return new Candidata('10.0.0.1:5432', $id, $eventos);
    }

    private function a(string $momento): CarbonImmutable
    {
        return CarbonImmutable::parse($momento);
    }

    public function test_la_primera_vez_que_ve_una_base_toma_la_copia_ya_con_los_ajustes_configurados(): void
    {
        $ruta = $this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-05 12:00:00'));

        $this->assertSame('/copias/auto-111.sql.gz', $ruta);
        $this->assertSame([['10.0.0.1:5432', '111', 'auto', '/copias', 8]], $this->pedidas);
    }

    public function test_aunque_la_base_este_vacia_la_primera_copia_se_toma(): void
    {
        $this->assertNotNull($this->servicio->tomarSiToca($this->base(0), $this->estado, $this->a('2026-11-05 12:00:00')));
    }

    public function test_no_repite_mientras_no_haya_eventos_nuevos_aunque_pase_el_tiempo(): void
    {
        $this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-05 12:00:00'));

        $this->assertNull($this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-09 12:00:00')));
        $this->assertCount(1, $this->pedidas);
    }

    public function test_con_eventos_nuevos_espera_al_intervalo(): void
    {
        $this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-05 12:00:00'));

        $this->assertNull($this->servicio->tomarSiToca($this->base(9), $this->estado, $this->a('2026-11-05 17:59:00')), 'aun no han pasado 6 h');
        $this->assertNotNull($this->servicio->tomarSiToca($this->base(9), $this->estado, $this->a('2026-11-05 18:00:00')));
        $this->assertCount(2, $this->pedidas);
    }

    public function test_lo_recordado_se_cuenta_desde_la_ultima_copia_hecha(): void
    {
        $this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-05 12:00:00'));
        $this->servicio->tomarSiToca($this->base(9), $this->estado, $this->a('2026-11-05 18:00:00'));

        $this->assertNull($this->servicio->tomarSiToca($this->base(12), $this->estado, $this->a('2026-11-05 23:00:00')));
        $this->assertNotNull($this->servicio->tomarSiToca($this->base(12), $this->estado, $this->a('2026-11-06 00:00:00')));
    }

    public function test_otra_base_distinta_tiene_su_propia_copia_inmediata(): void
    {
        $this->servicio->tomarSiToca($this->base(500), $this->estado, $this->a('2026-11-05 12:00:00'));

        $this->assertNotNull($this->servicio->tomarSiToca($this->base(0, '222'), $this->estado, $this->a('2026-11-05 12:05:00')), 'una base nueva (la vieja murio) se copia ya');
    }

    public function test_si_la_copia_falla_no_se_recuerda_y_se_reintenta_en_la_vuelta_siguiente(): void
    {
        $this->falla = true;
        try {
            $this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-05 12:00:00'));
            $this->fail('tenia que fallar');
        } catch (RuntimeException) {
        }
        $this->assertFileDoesNotExist($this->estado.'.copias');

        $this->falla = false;
        $this->assertNotNull($this->servicio->tomarSiToca($this->base(5), $this->estado, $this->a('2026-11-05 12:05:00')));
    }

    public function test_desactivada_no_hace_nada(): void
    {
        config(['nutrition.copias_automaticas.activa' => false]);

        $this->assertNull($this->servicio->tomarSiToca($this->base(5), $this->estado));
        $this->assertSame([], $this->pedidas);
    }
}
