<?php

namespace Tests\Feature;

use App\Enums\DiaSemana;
use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Enums\TipoFeedback;
use App\Filament\Resources\Alimentos\Pages\ListAlimentos;
use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Existencias\Pages\CreateExistencia;
use App\Filament\Resources\Existencias\Pages\ListExistencias;
use App\Filament\Resources\Hogares\Pages\EditHogar;
use App\Filament\Resources\Hogares\Pages\ListHogares;
use App\Filament\Resources\Hogares\RelationManagers\DisponibilidadRelationManager;
use App\Filament\Resources\Perfiles\Pages\CreatePerfil;
use App\Filament\Resources\Perfiles\Pages\EditPerfil;
use App\Filament\Resources\Perfiles\Pages\ListPerfiles;
use App\Filament\Resources\Perfiles\RelationManagers\FeedbackRelationManager;
use App\Filament\Resources\RegistrosComida\Pages\CreateRegistroComida;
use App\Filament\Resources\RegistrosComida\Pages\ListRegistrosComida;
use App\Models\Alimento;
use App\Models\Compra;
use App\Models\Existencia;
use App\Models\Hogar;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelHogarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_los_listados_cargan(): void
    {
        $hogar = Hogar::factory()->create();
        Perfil::factory()->for($hogar)->create();

        foreach ([ListHogares::class, ListPerfiles::class, ListAlimentos::class, ListExistencias::class, ListCompras::class, ListRegistrosComida::class] as $pagina) {
            Livewire::test($pagina)->assertSuccessful();
        }
    }

    public function test_crear_un_perfil_guarda_hogar_y_modelo_nutricional(): void
    {
        $hogar = Hogar::factory()->create();

        Livewire::test(CreatePerfil::class)
            ->fillForm([
                'hogar_id' => $hogar->id, 'nombre' => 'Marta', 'activo' => true, 'objetivo' => 'Bajar de peso', 'kcal_diarias' => 1700,
                'proteina_pct' => 30, 'carbohidratos_pct' => 40, 'grasa_pct' => 30, 'restricciones' => ['sin lácteos'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $perfil = Perfil::query()->sole();
        $this->assertSame($hogar->id, $perfil->hogar_id);
        $this->assertSame(['sin lácteos'], $perfil->restricciones);
        $this->assertSame(1700, $perfil->kcal_diarias);
    }

    public function test_los_macros_tienen_que_sumar_100(): void
    {
        $hogar = Hogar::factory()->create();

        Livewire::test(CreatePerfil::class)
            ->fillForm(['hogar_id' => $hogar->id, 'nombre' => 'Javier', 'proteina_pct' => 30, 'carbohidratos_pct' => 40, 'grasa_pct' => 40])
            ->call('create')
            ->assertHasFormErrors(['grasa_pct']);

        $this->assertSame(0, Perfil::query()->count());
    }

    public function test_editar_un_perfil_no_pierde_su_hogar(): void
    {
        $perfil = Perfil::factory()->create(['nombre' => 'Marta']);

        Livewire::test(EditPerfil::class, ['record' => $perfil->getKey()])
            ->fillForm(['nombre' => 'Marta G.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Marta G.', $perfil->fresh()->nombre);
        $this->assertSame($perfil->hogar_id, $perfil->fresh()->hogar_id);
    }

    public function test_los_tiempos_del_hogar_se_crean_y_no_se_repite_el_dia(): void
    {
        $hogar = Hogar::factory()->create();

        Livewire::test(DisponibilidadRelationManager::class, ['ownerRecord' => $hogar, 'pageClass' => EditHogar::class])
            ->callAction(TestAction::make('create')->table(), ['dia_semana' => DiaSemana::Lunes->value, 'minutos_comida' => 30, 'minutos_cena' => null])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $hogar->disponibilidad()->count());
        $this->assertNull($hogar->disponibilidad()->sole()->minutos_cena);

        Livewire::test(DisponibilidadRelationManager::class, ['ownerRecord' => $hogar, 'pageClass' => EditHogar::class])
            ->callAction(TestAction::make('create')->table(), ['dia_semana' => DiaSemana::Lunes->value, 'minutos_comida' => 20])
            ->assertHasFormErrors(['dia_semana']);

        $this->assertSame(1, $hogar->disponibilidad()->count());
    }

    public function test_el_feedback_se_crea_desde_el_perfil(): void
    {
        $perfil = Perfil::factory()->create();

        Livewire::test(FeedbackRelationManager::class, ['ownerRecord' => $perfil, 'pageClass' => EditPerfil::class])
            ->callAction(TestAction::make('create')->table(), ['tipo' => TipoFeedback::NoGusta->value, 'elemento' => 'coliflor'])
            ->assertHasNoFormErrors();

        $this->assertSame('coliflor', $perfil->feedback()->sole()->elemento);
        $this->assertSame(TipoFeedback::NoGusta, $perfil->feedback()->sole()->tipo);
    }

    public function test_registrar_una_compra_con_lineas_llena_las_existencias(): void
    {
        $hogar = Hogar::factory()->create();
        $arroz = Alimento::factory()->create(['nombre' => 'arroz']);

        Livewire::test(CreateCompra::class)
            ->fillForm([
                'hogar_id' => $hogar->id, 'fecha' => '2026-11-01', 'tienda' => 'Súper',
                'lineas' => [['alimento_id' => $arroz->id, 'cantidad' => 1000, 'unidad' => 'g']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $compra = Compra::query()->sole();
        $this->assertSame($hogar->id, $compra->hogar_id);
        $this->assertSame($arroz->id, $compra->lineas()->sole()->alimento_id);
        $existencia = Existencia::query()->sole();
        $this->assertSame('1000.00', $existencia->cantidad);
        $this->assertSame($hogar->id, $existencia->hogar_id);
    }

    public function test_una_compra_necesita_al_menos_una_linea(): void
    {
        $hogar = Hogar::factory()->create();

        Livewire::test(CreateCompra::class)
            ->fillForm(['hogar_id' => $hogar->id, 'fecha' => '2026-11-01', 'lineas' => []])
            ->call('create')
            ->assertHasFormErrors(['lineas']);

        $this->assertSame(0, Compra::query()->count());
    }

    public function test_crear_una_existencia_a_mano(): void
    {
        $hogar = Hogar::factory()->create();
        $leche = Alimento::factory()->create();

        Livewire::test(CreateExistencia::class)
            ->fillForm(['hogar_id' => $hogar->id, 'alimento_id' => $leche->id, 'cantidad' => 2, 'unidad' => 'l'])
            ->call('create')
            ->assertHasNoFormErrors();

        $existencia = Existencia::query()->sole();
        $this->assertSame([$hogar->id, $leche->id], [$existencia->hogar_id, $existencia->alimento_id]);
    }

    public function test_registrar_lo_comido_guarda_perfil_momento_y_origen(): void
    {
        $perfil = Perfil::factory()->create();

        Livewire::test(CreateRegistroComida::class)
            ->fillForm([
                'perfil_id' => $perfil->id, 'fecha' => '2026-11-02', 'momento' => MomentoComida::Cena->value,
                'origen' => OrigenComida::Fuera->value, 'descripcion' => 'Pizza en el restaurante', 'categorias' => ['cereal', 'lacteo'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $registro = RegistroComida::query()->sole();
        $this->assertSame($perfil->id, $registro->perfil_id);
        $this->assertSame([MomentoComida::Cena, OrigenComida::Fuera], [$registro->momento, $registro->origen]);
        $this->assertSame(['cereal', 'lacteo'], $registro->categorias);
    }
}
