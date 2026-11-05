<?php

namespace Tests\Feature;

use App\Services\BaseCompartida\CopiaDeRetirada;
use RuntimeException;
use Tests\Support\DosBases;
use Tests\TestCase;

/** La copia que se guarda de una base antes de pararla, contra PostgreSQL de verdad: entera, restaurable, y sin dejar restos si falla. */
class CopiaDeRetiradaTest extends TestCase
{
    use DosBases;

    private string $carpeta;

    private string $direccion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDosBases();
        $this->carpeta = sys_get_temp_dir().'/retiradas-'.uniqid();
        config(['nutrition.baja.copias' => $this->carpeta, 'nutrition.baja.copias_guardadas' => 5, 'database.connections.pgsql.database' => 'pn_fusion_a']);
        $this->direccion = config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta.'/*') ?: []);
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    private function datos(): void
    {
        $this->sql('a', "INSERT INTO hogares (id, nombre, created_at, updated_at) VALUES ('01000000-0000-7000-8000-000000000001', 'Casa', '2026-11-01', '2026-11-02 10:00:00')");
        $this->sql('a', "INSERT INTO perfiles (id, hogar_id, nombre, activo, created_at, updated_at) VALUES ('02000000-0000-7000-8000-000000000001', '01000000-0000-7000-8000-000000000001', 'Marta', true, '2026-11-01', '2026-11-02 10:00:00')");
    }

    public function test_guarda_un_volcado_entero_con_el_identificador_de_la_base_en_el_nombre(): void
    {
        $this->datos();

        $ruta = (new CopiaDeRetirada)->guardar($this->direccion, '77 04/7290');

        $this->assertFileExists($ruta);
        $this->assertMatchesRegularExpression('#/retirada-\d{4}-\d{2}-\d{2}-\d{6}-77047290\.sql\.gz$#', $ruta, 'solo letras y cifras en el nombre');
        $this->assertSame(0600, fileperms($ruta) & 0777);
        $sql = gzdecode((string) file_get_contents($ruta));
        $this->assertStringContainsString('INSERT INTO "hogares"', $sql);
        $this->assertStringContainsString('Marta', $sql);
        $this->assertStringContainsString('COMMIT;', $sql);
        $this->assertSame([], glob($this->carpeta.'/*.parcial') ?: [], 'sin ficheros a medias');
    }

    public function test_la_copia_se_puede_restaurar_en_otra_base(): void
    {
        $this->datos();
        $ruta = (new CopiaDeRetirada)->guardar($this->direccion, '1');

        $this->base('b')->exec((string) gzdecode((string) file_get_contents($ruta)));

        $this->assertSame(['Casa'], array_column($this->filas('b', 'SELECT nombre FROM hogares'), 'nombre'));
        $this->assertSame(['Marta'], array_column($this->filas('b', 'SELECT nombre FROM perfiles'), 'nombre'));
        $this->assertSame(0, (int) $this->valor('b', "SELECT count(*) FROM auditorias WHERE evento = 'creado' AND auditable_type = 'App\\Models\\Hogar'") - (int) $this->valor('a', "SELECT count(*) FROM auditorias WHERE evento = 'creado' AND auditable_type = 'App\\Models\\Hogar'"), 'la auditoria viaja con la copia y restaurar no anade movimientos');
    }

    public function test_se_conservan_solo_las_ultimas_copias(): void
    {
        $this->datos();
        config(['nutrition.baja.copias_guardadas' => 3]);
        mkdir($this->carpeta, 0700, true);
        foreach (range(1, 6) as $i) {
            $vieja = $this->carpeta."/retirada-2026-01-0{$i}-000000-x.sql.gz";
            file_put_contents($vieja, 'vieja');
            touch($vieja, strtotime("2026-01-0{$i} 00:00:00"));
        }

        $nueva = (new CopiaDeRetirada)->guardar($this->direccion, '9');

        $quedan = array_map('basename', glob($this->carpeta.'/retirada-*.sql.gz'));
        sort($quedan);
        $this->assertSame(['retirada-2026-01-05-000000-x.sql.gz', 'retirada-2026-01-06-000000-x.sql.gz', basename($nueva)], $quedan);
    }

    public function test_si_la_base_no_responde_falla_sin_dejar_restos(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            (new CopiaDeRetirada)->guardar('127.0.0.1:1', '1');
        } finally {
            $this->assertSame([], glob($this->carpeta.'/*') ?: [], 'ni la copia ni el fichero parcial');
        }
    }

    public function test_si_no_se_puede_crear_la_carpeta_falla(): void
    {
        config(['nutrition.baja.copias' => '/proc/no-se-puede/crear']);

        $this->expectException(RuntimeException::class);

        (new CopiaDeRetirada)->guardar($this->direccion, '1');
    }
}
