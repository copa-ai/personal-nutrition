<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\Feedback;
use App\Models\Menu;
use App\Models\RegistroComida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreaHogar;
use Tests\TestCase;

class EncargoMenuTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    public function test_el_encargo_lleva_semana_tiempos_perfiles_y_opciones(): void
    {
        $this->crearHogar(minutosComida: 30, minutosCena: 45);

        $encargo = $this->menu->construirEncargo();

        $this->assertSame('2026-11-02', $encargo['semana']['inicio']);
        $this->assertCount(7, $encargo['semana']['fechas']);
        $this->assertSame('2026-11-08', $encargo['semana']['fechas'][6]);
        $this->assertSame(['min' => 2, 'max' => 3], $encargo['opciones_por_comida']);
        $this->assertContains('carne_roja', $encargo['categorias_validas']);
        $this->assertCount(14, $encargo['categorias_validas']);
        $this->assertSame(['fecha' => '2026-11-02', 'dia_semana' => 1, 'minutos_comida' => 30, 'minutos_cena' => 45,
            'minutos_desayuno' => 10, 'minutos_almuerzo' => 10, 'minutos_merienda' => 5], $encargo['hogar']['tiempos'][0]);
        $this->assertSame(['Marta', 'Javier'], array_column($encargo['perfiles'], 'nombre'));
        $this->assertSame(['sin lácteos'], $encargo['perfiles'][0]['restricciones']);
    }

    public function test_las_tomas_individuales_sin_dato_viajan_como_null(): void
    {
        $this->crearHogar();
        $this->hogar->disponibilidad()->where('dia_semana', 1)->update(['minutos_desayuno' => null]);

        $tiempos = $this->menu->construirEncargo()['hogar']['tiempos'];

        $this->assertNull($tiempos[0]['minutos_desayuno']);
        $this->assertSame(10, $tiempos[1]['minutos_desayuno']);
    }

    public function test_el_modelo_nutricional_y_el_feedback_son_de_cada_perfil(): void
    {
        $this->crearHogar();
        $this->marta->update(['objetivo' => 'Bajar de peso', 'kcal_diarias' => 1700]);
        $this->javier->update(['objetivo' => 'Ganar peso', 'kcal_diarias' => 2800]);
        Feedback::factory()->for($this->marta, 'perfil')->noGusta('coliflor')->create();
        Feedback::factory()->for($this->javier, 'perfil')->gusta('legumbres')->create();

        [$marta, $javier] = $this->menu->construirEncargo()['perfiles'];

        $this->assertSame([1700, 'Bajar de peso', ['coliflor'], []], [$marta['kcal_diarias'], $marta['objetivo'], $marta['no_gusta'], $marta['gusta']]);
        $this->assertSame([2800, 'Ganar peso', [], ['legumbres']], [$javier['kcal_diarias'], $javier['objetivo'], $javier['no_gusta'], $javier['gusta']]);
    }

    public function test_un_perfil_inactivo_no_entra_en_el_encargo(): void
    {
        $this->crearHogar();
        $this->javier->update(['activo' => false]);

        $this->assertSame(['Marta'], array_column($this->menu->construirEncargo()['perfiles'], 'nombre'));
    }

    public function test_el_historial_es_lo_comido_de_verdad_y_respeta_la_ventana(): void
    {
        $this->crearHogar();
        RegistroComida::factory()->for($this->marta, 'perfil')->create(['fecha' => '2026-10-30', 'descripcion' => 'Pizza en el restaurante', 'origen' => 'fuera']);
        RegistroComida::factory()->for($this->marta, 'perfil')->create(['fecha' => '2026-09-01', 'descripcion' => 'Demasiado antigua']);

        $historial = $this->menu->construirEncargo()['perfiles'][0]['historial'];

        $this->assertCount(1, $historial);
        $this->assertSame(['fecha' => '2026-10-30', 'momento' => 'comida', 'origen' => 'fuera', 'descripcion' => 'Pizza en el restaurante', 'categorias' => []], $historial[0]);
    }

    public function test_lo_planificado_en_un_menu_anterior_no_cuenta_como_historial_pero_si_como_calendario(): void
    {
        $this->crearHogar();
        Menu::factory()->for($this->hogar)->create([
            'fecha_inicio' => '2026-10-26', 'estado' => 'terminado',
            'contenido' => ['dias' => [['fecha' => '2026-10-26', 'comida' => ['opciones' => [['titulo' => 'Lentejas'], ['titulo' => 'Paella']]], 'cena' => ['opciones' => []]]]],
        ]);

        $encargo = $this->menu->construirEncargo();

        $this->assertSame([], $encargo['perfiles'][0]['historial']);
        $this->assertSame('2026-10-26', $encargo['calendario_previo'][0]['inicio']);
        $this->assertSame(['Lentejas', 'Paella'], $encargo['calendario_previo'][0]['dias'][0]['comida']);
    }

    public function test_el_stock_incluye_solo_lo_que_hay(): void
    {
        $this->crearHogar();
        $arroz = Alimento::factory()->create(['nombre' => 'arroz', 'categoria' => 'cereal']);
        $vacio = Alimento::factory()->create(['nombre' => 'leche']);
        foreach ([[$arroz, 1000], [$vacio, 0]] as [$alimento, $cantidad]) {
            $x = $this->hogar->existencias()->make(['cantidad' => $cantidad, 'unidad' => 'g']);
            $x->alimento_id = $alimento->id;
            $x->save();
        }

        $stock = $this->menu->construirEncargo()['stock'];

        $this->assertSame([['alimento' => 'arroz', 'categoria' => 'cereal', 'cantidad' => 1000.0, 'unidad' => 'g', 'caduca_el' => null]], $stock);
    }

    public function test_sin_perfiles_activos_no_hay_encargo(): void
    {
        $this->crearHogar();
        $this->hogar->perfiles()->update(['activo' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ningún perfil activo');
        $this->menu->construirEncargo();
    }

    public function test_sin_tiempos_de_cocinado_no_hay_encargo(): void
    {
        $this->crearHogar();
        $this->hogar->disponibilidad()->update(['minutos_comida' => null, 'minutos_cena' => null, 'minutos_desayuno' => null, 'minutos_almuerzo' => null, 'minutos_merienda' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tiempos de cocinado');
        $this->menu->construirEncargo();
    }
}
