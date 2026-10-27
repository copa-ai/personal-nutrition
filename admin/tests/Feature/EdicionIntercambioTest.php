<?php

namespace Tests\Feature;

use App\Enums\EstadoMenu;
use App\Jobs\GenerarMenu;
use App\Models\Menu;
use App\Services\Menu\EjecutorAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Support\CreaHogar;
use Tests\TestCase;

/** Tareas «editar» e «intercambiar» del agente sobre un menú ya generado y activo. */
class EdicionIntercambioTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearHogar(minutosComida: 30, minutosCena: 45);
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $this->menu->importarEntrega($this->entregaValida());
        $this->menu->update(['estado' => EstadoMenu::Terminado]);
        $this->menu = $this->menu->fresh();
    }

    /** Deja el menú con la tarea y su encargo guardado, como hace EjecutorAgente al empezar. */
    private function preparar(string $tarea, string $instruccion): void
    {
        $this->menu->update(['tarea' => $tarea, 'instruccion' => $instruccion]);
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
    }

    private function cambioDeToma(): array
    {
        return $this->entregaValida()['dias'][1]['comida'];
    }

    public function test_pedir_edicion_pone_en_cola_y_lanza_el_job(): void
    {
        Queue::fake();

        $this->menu->pedirEdicion('  Sin pescado el jueves ');

        $m = $this->menu->fresh();
        $this->assertSame([EstadoMenu::EnCola, 'editar', 'Sin pescado el jueves', 0], [$m->estado, $m->tarea, $m->instruccion, $m->ciclo]);
        Queue::assertPushed(GenerarMenu::class, fn (GenerarMenu $j): bool => $j->menuId === $m->id);
    }

    public function test_no_se_pide_sin_texto_sin_contenido_ni_con_un_trabajo_en_curso(): void
    {
        Queue::fake();
        $casos = [
            fn () => $this->menu->pedirEdicion('   '),
            fn () => Menu::factory()->for($this->hogar)->create()->pedirEdicion('algo'),
            function () {
                $this->menu->update(['estado' => EstadoMenu::EnMarcha]);
                $this->menu->pedirIntercambio('algo');
            },
        ];
        foreach ($casos as $caso) {
            try {
                $caso();
                $this->fail('Tenía que lanzar InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_el_intercambio_solo_vale_para_el_menu_activo(): void
    {
        Queue::fake();
        $otro = Menu::factory()->for($this->hogar)->create(['fecha_inicio' => '2026-11-02']);
        $otro->update(['encargo' => $otro->construirEncargo()]);
        $otro->importarEntrega($this->entregaValida());   // el hogar ya tiene activo: este no lo es
        $otro->update(['estado' => EstadoMenu::Terminado]);

        $this->expectException(InvalidArgumentException::class);
        $otro->fresh()->pedirIntercambio('Comimos pizza');
    }

    public function test_el_prompt_usa_la_skill_de_cada_tarea(): void
    {
        $this->assertStringContainsString('skill planificar-menu', EjecutorAgente::prompt($this->menu));
        $this->menu->tarea = 'editar';
        $this->assertStringContainsString('skill editar-menu', EjecutorAgente::prompt($this->menu));
        $this->menu->tarea = 'intercambiar';
        $this->assertStringContainsString('skill intercambiar-menu', EjecutorAgente::prompt($this->menu));
    }

    public function test_el_encargo_de_editar_lleva_la_instruccion_y_el_contenido_actual(): void
    {
        $this->preparar('editar', 'Sin pescado');

        $encargo = $this->menu->encargo;

        $this->assertSame(['editar', 'Sin pescado'], [$encargo['tarea'], $encargo['instruccion']]);
        $this->assertSame('Ensalada', $encargo['contenido_actual']['dias'][0]['comida']['opciones'][0]['titulo']);
        $this->assertArrayNotHasKey('consumo', $encargo['contenido_actual']);
    }

    public function test_el_encargo_de_intercambiar_lleva_el_menu_activo_con_los_cambios_aplicados(): void
    {
        $cambio = ['categorias' => ['verdura'], 'opciones' => [['titulo' => 'Cambiada']]];
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-02/comida' => $cambio]])->save();
        $this->preparar('intercambiar', 'Comimos pizza en vez de la cena del lunes');

        $encargo = $this->menu->encargo;

        $this->assertSame('intercambiar', $encargo['tarea']);
        $this->assertCount(7, $encargo['menu_activo']);
        $this->assertSame('Cambiada', $encargo['menu_activo'][0]['comida']['opciones'][0]['titulo']);
        $this->assertSame(['2026-11-02/comida'], $encargo['cambios_aplicados']);
        $this->assertArrayNotHasKey('individuales', $encargo['menu_activo'][0]);
    }

    public function test_importar_una_edicion_guarda_el_menu_nuevo_conserva_selecciones_validas_y_descarta_cambios(): void
    {
        $this->menu->update(['selecciones' => ['2026-11-02/comida' => 1, '2026-11-03/cena' => 7]]);
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-04/comida' => $this->cambioDeToma()]])->save();
        $this->preparar('editar', 'Sin pescado');
        $datos = $this->entregaValida();
        $datos['dias'][0]['comida']['opciones'][0]['titulo'] = 'Gazpacho';

        $this->menu->importarEntrega($datos);

        $m = $this->menu->fresh();
        $this->assertSame('Gazpacho', $m->contenido['dias'][0]['comida']['opciones'][0]['titulo']);
        $this->assertSame(['2026-11-02/comida' => 1], $m->selecciones);
        $this->assertNull($this->hogar->fresh()->cambios);
    }

    public function test_una_edicion_invalida_no_cambia_nada(): void
    {
        $this->preparar('editar', 'Sin pescado');
        $datos = $this->entregaValida();
        $datos['explicaciones'] = [];

        try {
            $this->menu->importarEntrega($datos);
            $this->fail('Tenía que rechazar la entrega.');
        } catch (InvalidArgumentException) {
            $this->assertSame('Ensalada', $this->menu->fresh()->contenido['dias'][0]['comida']['opciones'][0]['titulo']);
        }
    }

    public function test_importar_un_intercambio_guarda_los_cambios_en_el_hogar_y_no_toca_el_menu(): void
    {
        $this->menu->update(['selecciones' => ['2026-11-03/comida' => 1, '2026-11-02/cena' => 1]]);
        $this->preparar('intercambiar', 'Comimos pizza');
        $contenido = $this->menu->fresh()->contenido;
        $datos = ['entrega' => ['completo' => true, 'version' => 3], 'cambios' => ['2026-11-03/comida' => $this->cambioDeToma() + ['extra' => 'x']]];
        $this->assertSame([], $this->menu->validarEntrega($datos));

        $this->menu->importarEntrega($datos);

        $hogar = $this->hogar->fresh();
        $this->assertSame(['2026-11-03/comida'], array_keys($hogar->cambios));
        $this->assertSame(['categorias', 'opciones'], array_keys($hogar->cambios['2026-11-03/comida']));
        $this->assertSame($contenido, $this->menu->fresh()->contenido);
        $this->assertSame(['2026-11-02/cena' => 1], $this->menu->fresh()->selecciones);
        $this->assertSame(['2026-11-03/comida'], $this->menu->fresh()->tomasCambiadas());
    }

    public function test_un_segundo_intercambio_se_suma_al_primero(): void
    {
        $this->preparar('intercambiar', 'Uno');
        $this->menu->importarEntrega(['entrega' => ['version' => 3], 'cambios' => ['2026-11-03/comida' => $this->cambioDeToma()]]);
        $this->menu = $this->menu->fresh();
        $this->preparar('intercambiar', 'Dos');

        $this->menu->importarEntrega(['entrega' => ['version' => 3], 'cambios' => ['2026-11-04/cena' => $this->entregaValida()['dias'][2]['cena']]]);

        $this->assertSame(['2026-11-03/comida', '2026-11-04/cena'], array_keys($this->hogar->fresh()->cambios));
    }

    public function test_un_intercambio_valida_cada_toma_y_sus_claves(): void
    {
        $this->preparar('intercambiar', 'Comimos pizza');
        $toma = $this->cambioDeToma();
        $malaToma = $toma;
        $malaToma['opciones'] = array_slice($toma['opciones'], 0, 1);

        $this->assertSame([], $this->menu->validarEntrega(['entrega' => ['version' => 3], 'cambios' => []]));
        $this->assertStringContainsString('Falta el objeto «cambios»', $this->menu->validarEntrega(['entrega' => ['version' => 3]])[0]);
        $this->assertCount(3, $this->menu->validarEntrega(['entrega' => ['version' => 3], 'cambios' => [
            '2026-11-20/comida' => $toma, '2026-11-03/desayuno' => $toma, 'sin-barra' => $toma,
        ]]));
        $errores = $this->menu->validarEntrega(['entrega' => ['version' => 3], 'cambios' => ['2026-11-03/comida' => $malaToma]]);
        $this->assertCount(1, $errores);
        $this->assertStringContainsString('2026-11-03/comida: hay 1 opciones', $errores[0]);
    }

    public function test_un_intercambio_no_se_importa_si_el_menu_dejo_de_ser_el_activo(): void
    {
        $this->preparar('intercambiar', 'Comimos pizza');
        $this->hogar->fresh()->activar(Menu::factory()->for($this->hogar)->create(['fecha_inicio' => '2026-11-09']));

        $this->expectException(InvalidArgumentException::class);
        $this->menu->fresh()->importarEntrega(['entrega' => ['version' => 3], 'cambios' => []]);
    }

    public function test_si_falla_editar_el_menu_queda_terminado_con_el_error_y_si_falla_generar_queda_fallido(): void
    {
        $this->menu->tarea = 'editar';
        $this->menu->cerrarConError('Algo falló');
        $this->assertSame([EstadoMenu::Terminado, 'Algo falló'], [$this->menu->fresh()->estado, $this->menu->fresh()->error]);

        $this->menu->tarea = 'generar';
        $this->menu->cerrarConError('Otra vez');
        $this->assertSame(EstadoMenu::Fallido, $this->menu->fresh()->estado);
    }

    public function test_descartar_cambios_del_hogar(): void
    {
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-04/comida' => $this->cambioDeToma()]])->save();

        $this->hogar->fresh()->descartarCambios();

        $this->assertNull($this->hogar->fresh()->cambios);
    }
}
