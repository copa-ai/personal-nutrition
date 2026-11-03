<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Services\Agente\AlmacenAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlmacenAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactivo_no_guarda_nada(): void
    {
        AlmacenAgente::borrar('workspace/x.json');

        $this->assertFalse(AlmacenAgente::activo());
        $this->assertFalse(AlmacenAgente::bajar(sys_get_temp_dir(), 'workspace/x.json'));
    }

    public function test_guarda_lee_y_borra_en_la_base(): void
    {
        config(['agente.almacen' => 'base']);

        AlmacenAgente::guardar('workspace/x.json', "binario\0y acentos: ñ");
        $this->assertSame("binario\0y acentos: ñ", AlmacenAgente::leer('workspace/x.json'));

        AlmacenAgente::guardar('workspace/x.json', 'nuevo');
        $this->assertSame('nuevo', AlmacenAgente::leer('workspace/x.json'));
        $this->assertDatabaseCount('agente_archivos', 1);

        AlmacenAgente::borrar('workspace/x.json');
        $this->assertNull(AlmacenAgente::leer('workspace/x.json'));
    }

    public function test_baja_un_archivo_al_disco(): void
    {
        config(['agente.almacen' => 'base']);
        $carpeta = sys_get_temp_dir().'/agente-'.uniqid();
        AlmacenAgente::guardar('workspace/resultado-4.json', '{"a":1}');

        $this->assertTrue(AlmacenAgente::bajar($carpeta, 'workspace/resultado-4.json'));
        $this->assertSame('{"a":1}', file_get_contents($carpeta.'/workspace/resultado-4.json'));
        $this->assertFalse(AlmacenAgente::bajar($carpeta, 'workspace/no-existe.json'));
    }

    public function test_solo_rutas_de_las_carpetas_que_se_intercambian(): void
    {
        $this->assertTrue(AlmacenAgente::rutaValida('workspace/a.json', AlmacenAgente::SALIDA));
        $this->assertTrue(AlmacenAgente::rutaValida('base/estado-actual.json', AlmacenAgente::SALIDA));
        $this->assertFalse(AlmacenAgente::rutaValida('encargos/a.json', AlmacenAgente::SALIDA));
        $this->assertFalse(AlmacenAgente::rutaValida('workspace/../.env', AlmacenAgente::SALIDA));
        $this->assertFalse(AlmacenAgente::rutaValida('/etc/passwd', AlmacenAgente::SALIDA));
        $this->assertFalse(AlmacenAgente::rutaValida('scripts/menu.py', AlmacenAgente::SALIDA));
    }

    public function test_el_registro_se_lee_de_la_base_si_el_almacen_esta_activo(): void
    {
        config(['agente.almacen' => 'base']);
        $menu = new Menu;
        $menu->id = 7;

        $this->assertNull(AlmacenAgente::registro($menu));

        AlmacenAgente::guardar(AlmacenAgente::rutaRegistro(7), str_repeat('x', 100).'final');
        $this->assertSame('xxxxxfinal', AlmacenAgente::registro($menu, 10));
    }
}
