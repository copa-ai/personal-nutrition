<?php

namespace Tests\Feature;

use App\Enums\EstadoMenu;
use App\Enums\OrigenComida;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\Menus\Pages\ViewMenu;
use App\Filament\Resources\RegistrosComida\Pages\CreateRegistroComida;
use App\Jobs\GenerarMenu;
use App\Models\Menu;
use App\Models\RegistroComida;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CreaHogar;
use Tests\TestCase;

/** Interfaz de menú activo, edición con comentario, cambios vigentes y formulario «Lo comido». */
class InterfazEdicionTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->crearHogar(minutosComida: 30, minutosCena: 45);
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $this->menu->importarEntrega($this->entregaValida());
        $this->menu->update(['estado' => EstadoMenu::Terminado]);
        $this->menu = $this->menu->fresh();
    }

    private function cambio(): array
    {
        $toma = $this->entregaValida()['dias'][1]['comida'];
        $toma['opciones'][0]['titulo'] = 'Plato cambiado';

        return $toma;
    }

    private function ver()
    {
        return Livewire::test(ViewMenu::class, ['record' => $this->menu->getRouteKey()]);
    }

    public function test_el_primer_menu_importado_queda_activo_y_se_ve_en_el_listado(): void
    {
        $this->assertSame($this->menu->id, $this->hogar->fresh()->menu_id);
        Livewire::test(ListMenus::class)->assertSuccessful()->assertTableColumnStateSet('activo', true, $this->menu);
    }

    public function test_activar_un_menu_cambia_el_activo_y_descarta_los_cambios(): void
    {
        $otro = Menu::factory()->for($this->hogar)->create(['fecha_inicio' => '2026-11-09']);
        $otro->update(['encargo' => $otro->construirEncargo()]);
        $primero = $this->menu;
        $this->menu = $otro;
        $otro->importarEntrega($this->entregaValida());
        $this->menu = $primero;
        $otro->update(['estado' => EstadoMenu::Terminado]);
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-03/comida' => $this->cambio()]])->save();

        Livewire::test(ViewMenu::class, ['record' => $otro->getRouteKey()])->callAction('activar')->assertHasNoActionErrors();

        $hogar = $this->hogar->fresh();
        $this->assertSame([$otro->id, null], [$hogar->menu_id, $hogar->cambios]);
    }

    public function test_editar_con_comentario_pone_en_cola_el_agente(): void
    {
        Queue::fake();

        $this->ver()->callAction('editar_con_comentario', ['comentario' => 'Sin pescado el jueves'])->assertHasNoActionErrors();

        $m = $this->menu->fresh();
        $this->assertSame([EstadoMenu::EnCola, 'editar', 'Sin pescado el jueves'], [$m->estado, $m->tarea, $m->instruccion]);
        Queue::assertPushed(GenerarMenu::class);
    }

    public function test_el_menu_vigente_aplica_los_cambios_y_el_por_defecto_no(): void
    {
        $this->ver()->assertActionHidden('ver_version')->assertActionHidden('descartar_cambios');
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-03/comida' => $this->cambio()]])->save();
        $menu = $this->menu->fresh();

        $this->assertSame('Plato cambiado', $menu->opcionesDe('2026-11-03', 'comida')[0]['titulo']);
        $this->assertSame('Plato cambiado', $menu->datosDocumento()['dias'][1]['tomas']['comida']['opcion']['titulo']);
        $this->assertTrue($menu->datosDocumento()['dias'][1]['tomas']['comida']['cambiada']);
        $porDefecto = $menu->datosDocumento(false);
        $this->assertNotSame('Plato cambiado', $porDefecto['dias'][1]['tomas']['comida']['opcion']['titulo']);
        $this->assertFalse($porDefecto['dias'][1]['tomas']['comida']['cambiada']);
        $this->assertNotSame('', $menu->generarPdf(false));

        $this->ver()->assertSee('Cambiada')->assertActionVisible('ver_version')
            ->callAction('ver_version')->assertSet('verPorDefecto', true)->assertDontSee('Plato cambiado');
    }

    public function test_descartar_cambios_deja_el_menu_original(): void
    {
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-03/comida' => $this->cambio()]])->save();

        $this->ver()->callAction('descartar_cambios');

        $this->assertNull($this->hogar->fresh()->cambios);
    }

    public function test_los_ajustes_por_perfil_se_ven_en_la_opcion_elegida(): void
    {
        $contenido = $this->menu->contenido;
        $contenido['dias'][0]['comida']['opciones'][0]['ajustes'] = [['perfil_id' => $this->marta->id, 'texto' => 'Apartar el arroz antes de mezclar']];
        $this->menu->update(['contenido' => $contenido]);

        $this->ver()->assertSee('Ajustes por persona')->assertSee('Apartar el arroz antes de mezclar');
    }

    public function test_lo_comido_con_lo_programado_guarda_un_registro_por_perfil_sin_pedir_intercambio(): void
    {
        Queue::fake();
        $titulo = $this->menu->opcionElegida('2026-11-02', 'comida')['titulo'];

        Livewire::test(CreateRegistroComida::class)
            ->fillForm(['perfiles' => [$this->marta->id, $this->javier->id], 'fecha' => '2026-11-02', 'momento' => 'comida'])
            ->assertFormFieldHidden('hogar_id')
            ->assertFormFieldDisabled('descripcion')
            ->call('create')
            ->assertHasNoFormErrors();

        $registros = RegistroComida::query()->get();
        $this->assertCount(2, $registros);
        $this->assertSame([OrigenComida::Menu, $titulo, $this->menu->id, '2026-11-02/comida/0'],
            [$registros[0]->origen, $registros[0]->descripcion, $registros[0]->menu_id, $registros[0]->opcion_ref]);
        Queue::assertNothingPushed();
    }

    public function test_otra_cosa_habilita_el_campo_y_pide_el_intercambio(): void
    {
        Queue::fake();

        Livewire::test(CreateRegistroComida::class)
            ->fillForm(['perfiles' => [$this->marta->id], 'fecha' => '2026-11-02', 'momento' => 'comida'])
            ->callAction(TestAction::make('otra_cosa')->schemaComponent('form-actions', schema: 'content'))
            ->assertFormFieldEnabled('descripcion')
            ->fillForm(['descripcion' => 'Pizza', 'origen' => OrigenComida::Fuera->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $registro = RegistroComida::query()->sole();
        $this->assertSame([OrigenComida::Fuera, 'Pizza', null], [$registro->origen, $registro->descripcion, $registro->menu_id]);
        $m = $this->menu->fresh();
        $this->assertSame([EstadoMenu::EnCola, 'intercambiar'], [$m->estado, $m->tarea]);
        $this->assertStringContainsString('Pizza', $m->instruccion);
        Queue::assertPushed(GenerarMenu::class);
    }
}
