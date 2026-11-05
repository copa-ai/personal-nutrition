<?php

namespace Tests\Feature;

use App\Services\BaseCompartida\Candidata;
use App\Services\BaseCompartida\CopiaDeRetirada;
use App\Services\BaseCompartida\Sonda;
use App\Services\Fusion\Fusionador;
use Carbon\CarbonImmutable;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `nutrition:vigilar-base` con un `celaut` falso (un script que responde lo que se le configura y apunta sus argumentos) y una sonda
 * simulada: elige la base, reescribe el fichero de estado y, solo si esta activado, para las que sobran.
 */
class VigilarBaseTest extends TestCase
{
    private string $carpeta;

    private string $estado;

    /** @var array<string, Candidata|null> direccion => lo que ve la sonda */
    private array $bases = [];

    /** @var list<array{0: string, 1: string}> fusiones pedidas: [origen, destino] */
    public array $fusiones = [];

    public ?\Closure $alFusionar = null;

    public bool $fusionFalla = false;

    /** @var list<array{0: string, 1: string}> copias pedidas antes de parar una base: [direccion, identificador] */
    public array $copias = [];

    public bool $copiaFalla = false;

    /** @var list<bool> por cada copia, si al hacerla la base todavia no se habia parado */
    public array $copiasAntesDeParar = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 12:00:00');
        $this->carpeta = sys_get_temp_dir().'/vigilar-'.uniqid();
        mkdir($this->carpeta);
        $this->estado = $this->carpeta.'/base-datos.json';
        $binario = $this->carpeta.'/celaut';
        file_put_contents($binario, <<<'SH'
#!/bin/sh
d=$(dirname "$0")
printf '%s\n' "$*" >> "$d/args"
for a in "$@"; do case "$a" in list|resolve|config|stop) orden=$a;; esac; done
[ -f "$d/$orden.json" ] && cat "$d/$orden.json" || echo '{}'
SH);
        chmod($binario, 0755);
        config([
            'nutrition.celaut.binario' => $binario,
            'nutrition.celaut.estado' => $this->carpeta.'/pool.json',
            'nutrition.baja.activa' => false,
            'nutrition.baja.silencio_min' => 10,
            'nutrition.eleccion.margen' => 0.05,
        ]);
        config(['nutrition.fusion.activa' => true]);
        $this->app->instance(CopiaDeRetirada::class, new class($this) extends CopiaDeRetirada
        {
            public function __construct(private readonly VigilarBaseTest $prueba) {}

            public function guardar(string $direccion, string $identificador): string
            {
                return $this->prueba->copiaPedida($direccion, $identificador);
            }
        });
        $this->app->instance(Fusionador::class, new class($this) extends Fusionador
        {
            public function __construct(private readonly VigilarBaseTest $prueba) {}

            public function fusionarBase(string $origen, string $destino): array
            {
                return $this->prueba->fusionPedida($origen, $destino);
            }
        });
        $this->app->instance(Sonda::class, new class($this) extends Sonda
        {
            public function __construct(private readonly VigilarBaseTest $prueba) {}

            public function sondear(string $host, int $puerto): ?Candidata
            {
                return $this->prueba->base("{$host}:{$puerto}");
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        array_map('unlink', glob($this->carpeta.'/*') ?: []);
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    public function base(string $direccion): ?Candidata
    {
        return $this->bases[$direccion] ?? null;
    }

    /** @return array<string, int> */
    public function fusionPedida(string $origen, string $destino): array
    {
        if ($this->fusionFalla) {
            throw new \RuntimeException('la base no contesta');
        }
        $this->fusiones[] = [$origen, $destino];
        $this->alFusionar && ($this->alFusionar)();

        return ['insertadas' => 1];
    }

    public function copiaPedida(string $direccion, string $identificador): string
    {
        if ($this->copiaFalla) {
            throw new \RuntimeException('disco lleno');
        }
        $this->copias[] = [$direccion, $identificador];
        $this->copiasAntesDeParar[] = ! str_contains($this->ordenesAlNodo(), ' stop ');

        return "/copias/retirada-{$identificador}.sql.gz";
    }

    /** Cambia lo que la sonda ve de una base (alguien escribio en ella). */
    private function escribieronEn(string $direccion, int $eventos, string $ultimo): void
    {
        $this->bases[$direccion] = new Candidata($direccion, $this->bases[$direccion]->identificador, $eventos, CarbonImmutable::parse($ultimo));
    }

    private function hay(string $direccion, string $id, int $eventos, ?string $ultimo = null): void
    {
        $this->bases[$direccion] = new Candidata($direccion, $id, $eventos, $ultimo === null ? null : CarbonImmutable::parse($ultimo));
    }

    private function escribirEstado(string $host, int $puerto, string $cluster = 'abc'): void
    {
        file_put_contents($this->estado, json_encode(['host' => $host, 'port' => $puerto, 'cluster' => $cluster]));
    }

    private function estadoActual(): array
    {
        return json_decode((string) file_get_contents($this->estado), true);
    }

    private function celaut(string $orden, array $datos): void
    {
        file_put_contents("{$this->carpeta}/{$orden}.json", json_encode($datos));
    }

    /** Lo que el nodo devuelve al resolver la red: un miembro por direccion. */
    private function vecinas(string ...$direcciones): void
    {
        $this->celaut('resolve', ['peers' => array_map(
            fn (string $d): array => ['slots' => [['internal_port' => 5432, 'transport' => ['tcp'], 'protocol_stack' => [['postgresql']], 'uris' => [$d]]]],
            $direcciones,
        )]);
    }

    private function pool(array ...$instancias): void
    {
        $this->celaut('list', ['instances' => array_map(
            fn (array $i): array => $i + ['service' => 'ab', 'leased' => true, 'idle_secs' => 0, 'timeouts' => 0, 'failures' => 0],
            $instancias,
        )]);
    }

    private function ordenesAlNodo(): string
    {
        return is_file("{$this->carpeta}/args") ? (string) file_get_contents("{$this->carpeta}/args") : '';
    }

    private function vigilar(): PendingCommand
    {
        return $this->artisan('nutrition:vigilar-base', ['estado' => $this->estado]);
    }

    public function test_sin_fichero_de_estado_no_hay_nada_que_vigilar(): void
    {
        $this->vigilar()->expectsOutputToContain('nada que vigilar')->assertSuccessful();

        $this->assertFileDoesNotExist($this->estado);
    }

    public function test_pasa_a_escribir_en_la_base_con_mas_eventos_y_conserva_el_cluster(): void
    {
        $this->escribirEstado('10.0.0.1', 5432, 'mi-cluster');
        $this->hay('10.0.0.1:5432', '111', 10, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 500, '2026-11-05 11:58:00');
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->expectsOutputToContain('Se pasa a escribir en 10.0.0.2:5432')->assertSuccessful();

        $this->assertSame(['host' => '10.0.0.2', 'port' => 5432, 'cluster' => 'mi-cluster'], $this->estadoActual());
    }

    public function test_dentro_del_margen_se_queda_donde_estaba(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 100);
        $this->hay('10.0.0.2:5432', '222', 104);
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->assertSuccessful();

        $this->assertSame('10.0.0.1', $this->estadoActual()['host']);
    }

    public function test_la_misma_base_por_dos_direcciones_cuenta_una_vez(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 50);
        $this->hay('203.0.113.7:6000', '111', 50);   // la misma base vista desde otro nodo
        $this->vecinas('203.0.113.7:6000');

        $this->vigilar()->assertSuccessful();

        $this->assertSame('10.0.0.1', $this->estadoActual()['host'], 'no salta a otra direccion de la misma base');
    }

    public function test_una_base_que_no_responde_se_descarta(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 5);
        $this->vecinas('10.0.0.9:5432');   // anunciada por la red pero sin respuesta

        $this->vigilar()->expectsOutputToContain('10.0.0.9:5432 no responde')->assertSuccessful();

        $this->assertSame('10.0.0.1', $this->estadoActual()['host']);
    }

    public function test_si_ninguna_responde_deja_el_estado_como_esta(): void
    {
        $this->escribirEstado('10.0.0.1', 5432, 'abc');
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->expectsOutputToContain('Ninguna base responde')->assertSuccessful();

        $this->assertSame(['host' => '10.0.0.1', 'port' => 5432, 'cluster' => 'abc'], $this->estadoActual());
    }

    public function test_si_la_actual_deja_de_responder_pasa_a_la_que_queda(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.2:5432', '222', 3);
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->assertSuccessful();

        $this->assertSame('10.0.0.2', $this->estadoActual()['host']);
    }

    public function test_pide_a_la_red_la_base_de_su_cluster(): void
    {
        $this->escribirEstado('10.0.0.1', 5432, 'mi-cluster');
        $this->hay('10.0.0.1:5432', '111', 1);

        $this->vigilar()->assertSuccessful();

        $this->assertStringContainsString('db.cluster=mi-cluster', $this->ordenesAlNodo());
    }

    public function test_con_la_baja_desactivada_la_base_que_sobra_se_deja_viva(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->expectsOutputToContain('la baja automatica esta desactivada')->assertSuccessful();

        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());
    }

    public function test_con_la_baja_activada_para_la_base_propia_que_sobra_y_esta_en_silencio(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->expectsOutputToContain('Se para la base 10.0.0.2:5432')->assertSuccessful();

        $this->assertStringContainsString(' stop 7', $this->ordenesAlNodo());
    }

    public function test_no_para_una_base_que_aun_recibe_escrituras(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:55:00');   // hace 5 min: no esta en silencio
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->assertSuccessful();

        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());
    }

    public function test_nunca_para_la_base_elegida_aunque_este_en_silencio(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-01 08:00:00');
        $this->pool(['lease' => 3, 'uri' => '10.0.0.1:5432']);

        $this->vigilar()->assertSuccessful();

        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());
    }

    public function test_no_para_una_base_que_no_es_suya(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');   // la lanzo otro dispositivo: no esta en este pool
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->assertSuccessful();

        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());
    }

    public function test_reconoce_su_base_aunque_el_pool_la_anuncie_por_otra_direccion(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->hay('172.16.0.2:5432', '222', 40, '2026-11-05 11:00:00');   // la misma base que 10.0.0.2, por la direccion del pool
        $this->vecinas('10.0.0.2:5432');
        $this->pool(['lease' => 9, 'uri' => '172.16.0.2:5432']);

        $this->vigilar()->assertSuccessful();

        $this->assertStringContainsString(' stop 9', $this->ordenesAlNodo());
    }

    public function test_un_nodo_que_no_responde_no_impide_elegir(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 5);
        file_put_contents($this->carpeta.'/celaut', "#!/bin/sh\necho '{\"error\":\"sin nodo\"}' >&2\nexit 3\n");

        $this->vigilar()->assertSuccessful();

        $this->assertSame('10.0.0.1', $this->estadoActual()['host']);
    }

    public function test_fusiona_en_la_elegida_la_base_que_sobra(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->expectsOutputToContain('Se fusiono 10.0.0.2:5432 en 10.0.0.1:5432')->assertSuccessful();

        $this->assertSame([['10.0.0.2:5432', '10.0.0.1:5432']], $this->fusiones);
    }

    public function test_fusiona_en_la_nueva_elegida_cuando_cambia_de_base(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 10, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 500, '2026-11-05 11:00:00');
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->assertSuccessful();

        $this->assertSame([['10.0.0.1:5432', '10.0.0.2:5432']], $this->fusiones, 'la que pierde entra en la que gana');
    }

    public function test_fusiona_cada_base_que_no_es_la_elegida(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500);
        $this->hay('10.0.0.2:5432', '222', 40);
        $this->hay('10.0.0.3:5432', '333', 7);
        $this->vecinas('10.0.0.2:5432', '10.0.0.3:5432');

        $this->vigilar()->assertSuccessful();

        $this->assertEqualsCanonicalizing([['10.0.0.2:5432', '10.0.0.1:5432'], ['10.0.0.3:5432', '10.0.0.1:5432']], $this->fusiones);
    }

    public function test_no_se_fusiona_la_misma_base_dos_veces_si_nadie_escribe_en_ella(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500);
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->vecinas('10.0.0.2:5432');

        $this->vigilar()->assertSuccessful();
        $this->vigilar()->assertSuccessful();

        $this->assertCount(1, $this->fusiones);
    }

    public function test_si_alguien_escribe_despues_de_la_fusion_se_vuelve_a_fusionar(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500);
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->vecinas('10.0.0.2:5432');
        $this->vigilar()->assertSuccessful();

        $this->escribieronEn('10.0.0.2:5432', 41, '2026-11-05 11:30:00');
        $this->vigilar()->assertSuccessful();

        $this->assertCount(2, $this->fusiones);
    }

    public function test_si_escriben_durante_la_fusion_no_se_da_por_fusionada(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);
        $this->alFusionar = fn () => $this->escribieronEn('10.0.0.2:5432', 41, '2026-11-05 11:30:00');

        $this->vigilar()->expectsOutputToContain('recibio escrituras durante la fusion')->assertSuccessful();
        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());

        $this->alFusionar = null;
        $this->vigilar()->assertSuccessful();
        $this->assertStringContainsString(' stop 7', $this->ordenesAlNodo(), 'a la vuelta siguiente, sin escrituras, ya se puede parar');
    }

    public function test_si_la_fusion_falla_no_se_para_la_base_y_el_comando_sigue(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);
        $this->fusionFalla = true;

        $this->vigilar()->expectsOutputToContain('No se pudo fusionar 10.0.0.2:5432')->assertSuccessful();

        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());
        $this->assertSame('10.0.0.1', $this->estadoActual()['host']);
    }

    public function test_con_la_fusion_desactivada_no_se_fusiona_y_por_tanto_no_se_para_nada(): void
    {
        config(['nutrition.fusion.activa' => false, 'nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->assertSuccessful();

        $this->assertSame([], $this->fusiones);
        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());
    }

    public function test_con_una_sola_base_no_hay_nada_que_fusionar(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500);

        $this->vigilar()->assertSuccessful();

        $this->assertSame([], $this->fusiones);
    }

    public function test_la_misma_base_por_otra_direccion_no_se_fusiona_consigo_misma(): void
    {
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 50);
        $this->hay('203.0.113.7:6000', '111', 50);
        $this->vecinas('203.0.113.7:6000');

        $this->vigilar()->assertSuccessful();

        $this->assertSame([], $this->fusiones);
    }

    public function test_guarda_una_copia_de_la_base_antes_de_pararla(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->expectsOutputToContain('Copia de 10.0.0.2:5432 guardada en /copias/retirada-222.sql.gz')->assertSuccessful();

        $this->assertSame([['10.0.0.2:5432', '222']], $this->copias);
        $this->assertSame([true], $this->copiasAntesDeParar, 'la copia se hace con la base todavia viva');
        $this->assertStringContainsString(' stop 7', $this->ordenesAlNodo());
    }

    public function test_si_no_se_puede_guardar_la_copia_no_se_para_la_base(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);
        $this->copiaFalla = true;

        $this->vigilar()->expectsOutputToContain('No se pudo guardar una copia de 10.0.0.2:5432: no se para. disco lleno')->assertSuccessful();

        $this->assertStringNotContainsString(' stop ', $this->ordenesAlNodo());

        $this->copiaFalla = false;
        $this->vigilar()->assertSuccessful();
        $this->assertStringContainsString(' stop 7', $this->ordenesAlNodo(), 'al repetirse, con la copia posible, se para');
    }

    public function test_no_se_hace_copia_de_una_base_que_no_se_va_a_parar(): void
    {
        config(['nutrition.baja.activa' => true]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');      // la elegida
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:55:00');       // escribieron hace 5 min
        $this->hay('10.0.0.3:5432', '333', 7, '2026-11-05 11:00:00');        // no es suya (no esta en el pool)
        $this->vecinas('10.0.0.3:5432');
        $this->pool(['lease' => 1, 'uri' => '10.0.0.1:5432'], ['lease' => 2, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->assertSuccessful();

        $this->assertSame([], $this->copias);
    }

    public function test_con_la_baja_desactivada_no_se_hace_copia(): void
    {
        config(['nutrition.baja.activa' => false]);
        $this->escribirEstado('10.0.0.1', 5432);
        $this->hay('10.0.0.1:5432', '111', 500, '2026-11-05 11:59:00');
        $this->hay('10.0.0.2:5432', '222', 40, '2026-11-05 11:00:00');
        $this->pool(['lease' => 7, 'uri' => '10.0.0.2:5432']);

        $this->vigilar()->assertSuccessful();

        $this->assertSame([], $this->copias);
    }

    public function test_la_baja_automatica_esta_activada_por_defecto(): void
    {
        $this->assertTrue((bool) (require base_path('config/nutrition.php'))['baja']['activa']);
    }
}
