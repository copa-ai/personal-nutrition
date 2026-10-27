<?php

namespace Tests\Feature;

use App\Enums\EstadoMenu;
use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Filament\Pages\CalendarioComidas;
use App\Filament\Support\DatosCalendario;
use App\Models\Hogar;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreaHogar;
use Tests\TestCase;

/** Calendario de lo comido (días pasados) y lo programado en el menú activo (de hoy en adelante), por perfil. */
class CalendarioComidasTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-04 10:00:00');
        $this->actingAs(User::factory()->create());
        $this->crearHogar(minutosComida: 30, minutosCena: 45);
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $this->menu->importarEntrega($this->entregaValida());
        $this->menu->update(['estado' => EstadoMenu::Terminado]);
    }

    private function comido(string $fecha, MomentoComida $momento, string $descripcion, ?int $perfilId = null, OrigenComida $origen = OrigenComida::Casa): RegistroComida
    {
        return RegistroComida::factory()->create(['perfil_id' => $perfilId ?? $this->marta->id, 'fecha' => $fecha, 'momento' => $momento, 'origen' => $origen, 'descripcion' => $descripcion]);
    }

    /** @return list<array<string, mixed>> */
    private function dias(string $desde, string $hasta, ?int $perfilId = null): array
    {
        $perfil = $perfilId === $this->javier->id ? $this->javier : $this->marta;

        return (new DatosCalendario($this->hogar->fresh(), $perfil, CarbonImmutable::parse('2026-11-04')))->dias(CarbonImmutable::parse($desde), CarbonImmutable::parse($hasta));
    }

    private function pagina(): Testable
    {
        return Livewire::test(CalendarioComidas::class)->set('perfilId', $this->marta->id);
    }

    public function test_lo_comido_manda_sobre_lo_programado_y_lo_programado_sin_registro_en_el_pasado_se_marca(): void
    {
        $this->comido('2026-11-02', MomentoComida::Comida, 'Lentejas');

        [$lunes, $martes] = $this->dias('2026-11-02', '2026-11-03');

        $this->assertSame(['comido', 'Lentejas'], [$lunes['tomas']['comida']['tipo'], $lunes['tomas']['comida']['titulo']]);
        $this->assertSame(['sin_registro', 'Tortilla'], [$lunes['tomas']['cena']['tipo'], $lunes['tomas']['cena']['titulo']]);
        $this->assertSame('sin_registro', $martes['tomas']['comida']['tipo']);
    }

    public function test_de_hoy_en_adelante_se_ve_lo_programado_con_sus_opciones_y_las_tomas_individuales(): void
    {
        [$hoy] = $this->dias('2026-11-04', '2026-11-04');

        $this->assertTrue($hoy['hoy']);
        $this->assertSame(['programado', 'Ensalada', ['Ensalada', 'Crema'], 0], [$hoy['tomas']['comida']['tipo'], $hoy['tomas']['comida']['titulo'], $hoy['tomas']['comida']['opciones'], $hoy['tomas']['comida']['elegida']]);
        $this->assertSame(['Desayuno', 'Almuerzo', 'Merienda'], array_column($hoy['individuales'], 'etiqueta'));
        $this->assertSame([], $this->dias('2026-11-03', '2026-11-03')[0]['individuales']);
    }

    public function test_la_opcion_elegida_y_los_cambios_vigentes_se_reflejan(): void
    {
        $this->menu->elegirOpcion('2026-11-05', 'cena', 1);
        $toma = $this->entregaValida()['dias'][3]['comida'];
        $toma['opciones'][0]['titulo'] = 'Plato cambiado';
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-05/comida' => $toma]])->save();

        [$jueves] = $this->dias('2026-11-05', '2026-11-05');

        $this->assertSame('Pescado', $jueves['tomas']['cena']['titulo']);
        $this->assertSame('Plato cambiado', $jueves['tomas']['comida']['titulo']);
    }

    public function test_cada_perfil_ve_solo_lo_que_comio_el_y_lo_extra_va_aparte(): void
    {
        $this->comido('2026-11-02', MomentoComida::Comida, 'Lentejas');
        $this->comido('2026-11-02', MomentoComida::Comida, 'Pizza', $this->javier->id, OrigenComida::Fuera);
        $this->comido('2026-11-02', MomentoComida::Otro, 'Café con bizcocho');

        [$marta] = $this->dias('2026-11-02', '2026-11-02');
        [$javier] = $this->dias('2026-11-02', '2026-11-02', $this->javier->id);

        $this->assertSame('Lentejas', $marta['tomas']['comida']['titulo']);
        $this->assertSame([['titulo' => 'Café con bizcocho', 'origen' => 'Casa, sin seguir el menú']], $marta['otros']);
        $this->assertSame(['Pizza', 'Fuera de casa', []], [$javier['tomas']['comida']['titulo'], $javier['tomas']['comida']['origen'], $javier['otros']]);
    }

    public function test_sin_menu_activo_solo_salen_los_registros(): void
    {
        $this->hogar->fresh()->forceFill(['menu_id' => null])->save();
        $this->comido('2026-11-10', MomentoComida::Cena, 'Sopa');

        $dias = $this->dias('2026-11-09', '2026-11-10');

        $this->assertSame([null, null], [$dias[0]['tomas']['comida'], $dias[0]['tomas']['cena']]);
        $this->assertSame('Sopa', $dias[1]['tomas']['cena']['titulo']);
    }

    public function test_el_registro_del_ultimo_dia_del_rango_entra(): void
    {
        $this->comido('2026-11-08', MomentoComida::Comida, 'Paella');

        $dias = $this->dias('2026-11-02', '2026-11-08');

        $this->assertCount(7, $dias);
        $this->assertSame('Paella', $dias[6]['tomas']['comida']['titulo']);
    }

    public function test_la_pagina_enseña_el_mes_con_lo_comido_y_lo_programado(): void
    {
        $this->comido('2026-11-02', MomentoComida::Comida, 'Lentejas');

        $this->pagina()->assertSuccessful()->assertSee('Noviembre 2026')->assertSee('Lentejas')->assertSee('Tortilla')->assertSee('Programado y sin registrar');
    }

    public function test_el_detalle_del_dia_lista_las_opciones_y_lo_anotado(): void
    {
        $this->comido('2026-11-04', MomentoComida::Cena, 'Sándwich');

        $this->pagina()->assertDontSee('Resto de tomas')->call('elegirDia', '2026-11-04')
            ->assertSee('Miércoles 4 de noviembre')->assertSee('Crema')->assertSee('(elegida)')->assertSee('Sándwich')->assertSee('Resto de tomas')->assertSee('Tostada');
    }

    public function test_navegar_mueve_un_mes_o_una_semana_segun_la_vista(): void
    {
        $this->pagina()->call('mover', 1)->assertSet('ancla', '2026-12-04')->assertSee('Diciembre 2026')
            ->call('irAHoy')->assertSet('ancla', '2026-11-04')->assertSet('diaElegido', '2026-11-04')
            ->call('cambiarVista', 'semana')->call('mover', -1)->assertSet('ancla', '2026-10-28')->assertSee('Del 26 de octubre al 1 de noviembre de 2026');
    }

    public function test_el_fin_de_mes_no_se_desborda(): void
    {
        $this->pagina()->set('ancla', '2026-01-31')->call('mover', 1)->assertSet('ancla', '2026-02-28');
    }

    public function test_los_valores_raros_del_navegador_se_ignoran(): void
    {
        $this->pagina()->call('cambiarVista', 'año')->assertSet('vista', 'mes')
            ->call('elegirDia', 'no-es-una-fecha')->assertSet('diaElegido', null)
            ->set('ancla', 'basura')->assertSuccessful()->assertSee('Noviembre 2026')
            ->set('hogarId', 999)->assertSuccessful()
            ->set('perfilId', 999)->assertSuccessful();
    }

    public function test_un_perfil_de_otro_hogar_no_se_puede_ver(): void
    {
        $otro = Hogar::factory()->create(['nombre' => 'Otra casa']);
        $ajeno = Perfil::factory()->for($otro)->create(['nombre' => 'Ajeno']);
        $this->comido('2026-11-02', MomentoComida::Comida, 'Secreto', $ajeno->id);

        Livewire::test(CalendarioComidas::class)->set('hogarId', $this->hogar->id)->set('perfilId', $ajeno->id)->assertDontSee('Secreto');
    }

    public function test_sin_hogares_se_explica_que_falta_crearlos(): void
    {
        Hogar::query()->delete();

        Livewire::test(CalendarioComidas::class)->assertSuccessful()->assertSee('Todavía no hay ningún hogar con perfiles');
    }
}
