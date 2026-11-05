<?php

namespace Tests\Feature;

use App\Services\BaseCompartida\EstadoBase;
use Tests\TestCase;

/** La aplicacion sigue el fichero de estado: si apunta a otra base, se reconecta sin reiniciar. */
class EstadoBaseTest extends TestCase
{
    private string $fichero;

    protected function setUp(): void
    {
        parent::setUp();
        EstadoBase::olvidar();
        $this->fichero = sys_get_temp_dir().'/estado-base-'.uniqid().'.json';
        config(['database.connections.pgsql.host' => '10.0.0.1', 'database.connections.pgsql.port' => 5432]);
    }

    protected function tearDown(): void
    {
        @unlink($this->fichero);
        EstadoBase::olvidar();
        parent::tearDown();
    }

    public function test_sin_fichero_configurado_no_toca_nada(): void
    {
        config(['nutrition.base_estado' => null]);

        EstadoBase::aplicar();

        $this->assertSame('10.0.0.1', config('database.connections.pgsql.host'));
    }

    public function test_un_fichero_que_no_existe_o_esta_roto_no_cambia_la_conexion(): void
    {
        config(['nutrition.base_estado' => $this->fichero]);
        EstadoBase::aplicar();
        file_put_contents($this->fichero, '{no es json');
        EstadoBase::aplicar();
        file_put_contents($this->fichero, json_encode(['host' => '10.0.0.9']));   // sin puerto
        EstadoBase::aplicar();

        $this->assertSame(['10.0.0.1', 5432], [config('database.connections.pgsql.host'), config('database.connections.pgsql.port')]);
    }

    public function test_si_el_fichero_apunta_a_otra_base_cambia_la_conexion(): void
    {
        config(['nutrition.base_estado' => $this->fichero]);
        EstadoBase::escribir($this->fichero, '10.0.0.2', 6543, 'abc');

        EstadoBase::aplicar();

        $this->assertSame(['10.0.0.2', 6543], [config('database.connections.pgsql.host'), config('database.connections.pgsql.port')]);
    }

    public function test_cambia_cada_vez_que_el_fichero_cambia_y_no_si_sigue_igual(): void
    {
        config(['nutrition.base_estado' => $this->fichero]);
        EstadoBase::escribir($this->fichero, '10.0.0.2', 5432, 'abc');
        EstadoBase::aplicar();
        config(['database.connections.pgsql.host' => 'tocada-a-mano']);

        EstadoBase::aplicar();   // el fichero sigue diciendo 10.0.0.2: no se vuelve a aplicar
        $this->assertSame('tocada-a-mano', config('database.connections.pgsql.host'));

        EstadoBase::escribir($this->fichero, '10.0.0.3', 5432, 'abc');
        EstadoBase::aplicar();
        $this->assertSame('10.0.0.3', config('database.connections.pgsql.host'));
    }

    public function test_leer_y_escribir_conservan_el_formato_de_start_sh(): void
    {
        EstadoBase::escribir($this->fichero, '10.0.0.2', 5432, 'mi-cluster');

        $this->assertSame(['host' => '10.0.0.2', 'port' => 5432, 'cluster' => 'mi-cluster'], json_decode((string) file_get_contents($this->fichero), true));
        $this->assertSame(['host' => '10.0.0.2', 'port' => 5432, 'cluster' => 'mi-cluster'], EstadoBase::leer($this->fichero));
        $this->assertFileDoesNotExist($this->fichero.'.tmp');
    }
}
