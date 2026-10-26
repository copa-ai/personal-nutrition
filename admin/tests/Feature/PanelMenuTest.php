<?php

namespace Tests\Feature;

use App\Enums\EstadoMenu;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\Menus\Pages\ViewMenu;
use App\Filament\Support\PestanaConsumo;
use App\Jobs\GenerarMenu;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreaHogar;
use Tests\TestCase;

class PanelMenuTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function ver(): Testable
    {
        return Livewire::test(ViewMenu::class, ['record' => $this->menu->getRouteKey()]);
    }

    /** Menú terminado: encargo guardado, entrega importada con una fila de consumo. */
    private function terminarMenu(): void
    {
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $entrega = $this->entregaValida();
        $entrega['dias'][0]['comida']['opciones'][0]['titulo'] = 'Arroz con pollo';
        $entrega['dias'][0]['comida']['opciones'][0]['adaptaciones'] = [
            ['perfil_id' => $this->marta->id, 'cambios' => 'Sin queso y doble de verdura.'],
            ['perfil_id' => $this->javier->id, 'cambios' => 'Ración doble.'],
        ];
        $entrega['consumo'] = ['filas' => [[
            'mensaje_id' => 'm1', 'fase' => 'planificacion', 'modelo' => 'claude-sonnet-5-5', 'input_tokens' => 12000, 'output_tokens' => 3000,
        ]]];
        $this->menu->importarEntrega($entrega);
        $this->menu->update(['estado' => EstadoMenu::Terminado, 'ciclo' => 1]);
    }

    public function test_el_listado_y_la_ficha_cargan(): void
    {
        $this->crearHogar();

        Livewire::test(ListMenus::class)->assertSuccessful()->assertCanSeeTableRecords([$this->menu]);
        $this->ver()->assertSuccessful()->assertSee('Todavía no hay menú');
    }

    public function test_crear_un_menu_lo_deja_en_borrador_y_va_a_su_ficha(): void
    {
        $this->crearHogar();

        Livewire::test(CreateMenu::class)
            ->fillForm(['nombre' => 'Semana 45', 'hogar_id' => $this->hogar->id, 'fecha_inicio' => '2026-11-09'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $nuevo = Menu::query()->where('nombre', 'Semana 45')->sole();
        $this->assertSame(EstadoMenu::Borrador, $nuevo->estado);
        $this->assertSame($this->hogar->id, $nuevo->hogar_id);
        $this->assertSame('2026-11-09', $nuevo->fecha_inicio->toDateString());
    }

    public function test_generar_pone_el_menu_en_cola_y_lanza_el_job(): void
    {
        Queue::fake();
        $this->crearHogar();

        $this->ver()->callAction('ejecutar')->assertNotified('En cola');

        $this->assertSame(EstadoMenu::EnCola, $this->menu->fresh()->estado);
        Queue::assertPushed(GenerarMenu::class, fn (GenerarMenu $j): bool => $j->menuId === $this->menu->id);
    }

    public function test_generar_sin_perfiles_activos_avisa_y_no_lanza_nada(): void
    {
        Queue::fake();
        $this->crearHogar();
        $this->hogar->perfiles()->update(['activo' => false]);

        $this->ver()->callAction('ejecutar')->assertNotified('Falta información para generar el menú');

        $this->assertSame(EstadoMenu::Borrador, $this->menu->fresh()->estado);
        Queue::assertNothingPushed();
    }

    public function test_las_acciones_solo_aparecen_en_su_estado(): void
    {
        $this->crearHogar();
        $this->ver()->assertActionVisible('ejecutar')->assertActionHidden('detener')->assertActionHidden('forzar_parada')->assertActionHidden('reintentar');

        $this->menu->update(['estado' => EstadoMenu::EnMarcha]);
        $this->ver()->assertActionHidden('ejecutar')->assertActionVisible('detener')->assertActionVisible('forzar_parada');

        $this->menu->update(['estado' => EstadoMenu::EnEspera]);
        $this->ver()->assertActionVisible('reintentar')->assertActionVisible('detener')->assertActionHidden('forzar_parada');

        $this->menu->update(['estado' => EstadoMenu::Fallido]);
        $this->ver()->assertActionVisible('ejecutar');
    }

    public function test_detener_un_menu_en_cola_lo_devuelve_a_borrador(): void
    {
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::EnCola]);

        $this->ver()->callAction('detener');

        $this->assertSame(EstadoMenu::Borrador, $this->menu->fresh()->estado);
    }

    public function test_detener_un_menu_en_marcha_solo_pide_la_parada(): void
    {
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::EnMarcha]);

        $this->ver()->callAction('detener');

        $this->assertSame(EstadoMenu::EnMarcha, $this->menu->fresh()->estado);
        $this->assertTrue($this->menu->fresh()->detener_pedido);
    }

    public function test_forzar_la_parada_cancela_en_el_agente_y_cierra_el_estado(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        config(['agente.remoto.url' => 'http://agente:9000', 'agente.remoto.secreto' => 'secreto-de-prueba-123']);
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::EnMarcha, 'ejecucion_id' => 'abc123']);

        $this->ver()->callAction('forzar_parada');

        $m = $this->menu->fresh();
        $this->assertSame([EstadoMenu::Borrador, null], [$m->estado, $m->ejecucion_id]);
        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ejecuciones/abc123'));
    }

    public function test_forzar_la_parada_de_un_menu_con_resultado_lo_deja_terminado(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->crearHogar();
        $this->terminarMenu();
        $this->menu->update(['estado' => EstadoMenu::EnMarcha]);

        $this->ver()->callAction('forzar_parada');

        $this->assertSame(EstadoMenu::Terminado, $this->menu->fresh()->estado);
    }

    public function test_la_ficha_muestra_el_menu_con_las_adaptaciones_por_perfil(): void
    {
        $this->crearHogar();
        $this->terminarMenu();

        $this->ver()
            ->assertSee('Arroz con pollo')
            ->assertSee('Marta')->assertSee('Sin queso y doble de verdura.')
            ->assertSee('Javier')->assertSee('Ración doble.')
            ->assertSee('Lunes 2 de noviembre')
            ->assertSee('7 de 7');
    }

    public function test_la_pestana_consumo_muestra_el_coste_congelado(): void
    {
        $this->crearHogar();
        $this->terminarMenu();

        $this->ver()->assertSee('$0,054')->assertSee('claude-sonnet-5-5')->assertSee('Planificación');
    }

    public function test_el_progreso_sale_de_las_fases_del_registro(): void
    {
        $this->crearHogar();
        $log = tempnam(sys_get_temp_dir(), 'log');
        file_put_contents($log, "[10:00:01] 🔧 Herramienta: python scripts/consumos.py fase lectura --ejecucion 1\n"
            ."[10:00:30] 🔧 Herramienta: python scripts/consumos.py fase planificacion --ejecucion 1\n[10:01:00] 💬 Pensando…\n");
        $this->menu->update(['estado' => EstadoMenu::EnMarcha, 'archivo_log' => $log]);

        $m = $this->menu->fresh();
        $this->assertSame('planificacion', $m->faseActual());
        $this->assertSame([45, 'Planificando el menú'], [$m->progreso(), $m->etiquetaProgreso()]);
        $this->ver()->assertSee('Planificando el menú')->assertSee('45 %')->assertSee('Pensando');

        $this->menu->update(['estado' => EstadoMenu::EnCola]);
        $this->assertSame(0, $this->menu->fresh()->progreso());
        $this->menu->update(['estado' => EstadoMenu::Terminado]);
        $this->assertSame(100, $this->menu->fresh()->progreso());
        unlink($log);
    }

    public function test_un_menu_fallido_explica_el_error_y_no_muestra_progreso(): void
    {
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::Fallido, 'error' => 'La entrega del agente no es válida: falta algo.']);

        $this->ver()->assertSee('No se pudo generar el menú')->assertSee('falta algo')->assertDontSee('Estimación según la fase');
    }

    public function test_un_borrador_no_muestra_progreso_ni_coste(): void
    {
        $this->crearHogar();

        $this->ver()->assertDontSee('Estimación según la fase')->assertDontSee('Coste estimado')->assertSee('Todavía no hay menú');
    }

    public function test_mientras_se_genera_se_ve_el_avance_y_se_explica_donde_seguirlo(): void
    {
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::EnMarcha]);

        $this->ver()->assertSee('Estimación según la fase')->assertSee('El agente está preparando el menú');
    }

    public function test_la_pestana_compra_lista_lo_que_hay_que_comprar_con_lo_elegido(): void
    {
        $this->crearHogar();
        $this->terminarMenu();

        $this->ver()->assertSee('por comprar')->assertSee('Hay que comprar')->assertSee('tomate');
    }

    public function test_los_importes_y_duraciones_se_formatean_con_claridad(): void
    {
        $this->assertSame('< $0,001', PestanaConsumo::dinero(0.0001));
        $this->assertSame('$0,000', PestanaConsumo::dinero(0.0));
        $this->assertSame('$0,153', PestanaConsumo::dinero(0.153));
        $this->assertSame('$12,50', PestanaConsumo::dinero(12.5));
        $this->assertSame('Sin tarifa', PestanaConsumo::dinero(null));
        $this->assertSame(['44 s', '5 min', '5 min 12 s', '1 h 3 min'], array_map([PestanaConsumo::class, 'duracion'], [44, 300, 312, 3780]));
        $this->assertSame(['Inicio', 'Planificación', 'Fase rara'], array_map([PestanaConsumo::class, 'etiquetaFase'], ['sin fase', 'planificacion', 'fase rara']));
    }

    public function test_sin_registro_se_dice(): void
    {
        $this->crearHogar();

        $this->assertNull($this->menu->faseActual());
        $this->ver()->assertSee('Todavía no hay registro');
    }

    public function test_un_menu_en_curso_no_se_puede_borrar_ni_editar(): void
    {
        $this->crearHogar();
        $this->menu->update(['estado' => EstadoMenu::EnMarcha]);

        Livewire::test(EditMenu::class, ['record' => $this->menu->getRouteKey()])->assertActionHidden('delete');

        $this->menu->update(['estado' => EstadoMenu::Borrador]);
        Livewire::test(EditMenu::class, ['record' => $this->menu->getRouteKey()])->assertActionVisible('delete');
    }
}
