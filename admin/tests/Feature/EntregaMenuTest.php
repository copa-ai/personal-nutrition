<?php

namespace Tests\Feature;

use App\Models\ConsumoMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreaHogar;
use Tests\TestCase;

class EntregaMenuTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearHogar(minutosComida: 30, minutosCena: 45);
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
    }

    public function test_una_entrega_valida_no_da_errores_y_se_importa(): void
    {
        $datos = $this->entregaValida();
        $this->assertSame([], $this->menu->validarEntrega($datos));

        $this->menu->importarEntrega($datos);

        $this->assertCount(7, $this->menu->fresh()->contenido['dias']);
        $this->assertNotNull($this->menu->fresh()->huella);
    }

    public function test_el_consumo_va_al_libro_y_no_se_queda_en_el_contenido(): void
    {
        $datos = $this->entregaValida();
        $datos['consumo'] = ['totales' => ['respuestas' => 1], 'filas' => [[
            'mensaje_id' => 'msg_1', 'fase' => 'planificacion', 'agente' => 'principal', 'modelo' => 'claude-sonnet-5-5',
            'input_tokens' => 1000, 'output_tokens' => 500,
        ]]];

        $this->menu->importarEntrega($datos);

        $this->assertSame(1, ConsumoMenu::query()->where('menu_id', $this->menu->id)->count());
        $this->assertArrayNotHasKey('filas', $this->menu->fresh()->contenido['consumo']);
        $this->assertSame(['respuestas' => 1], $this->menu->fresh()->contenido['consumo']['totales']);
    }

    public function test_una_entrega_invalida_no_guarda_nada_y_dice_por_que(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['comida']['opciones'][0]['adaptaciones'] = [['perfil_id' => $this->marta->id, 'cambios' => 'Sin cambios.']];

        try {
            $this->menu->importarEntrega($datos);
            $this->fail('Debía lanzar una excepción.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('una adaptación por perfil', $e->getMessage());
        }

        $this->assertNull($this->menu->fresh()->contenido);
    }

    public function test_cada_opcion_necesita_una_adaptacion_por_cada_perfil(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][2]['cena']['opciones'][1]['adaptaciones'] = [];

        $errores = $this->menu->validarEntrega($datos);

        $this->assertCount(1, $errores);
        $this->assertStringContainsString('2026-11-04 · cena · opción 2', $errores[0]);
    }

    public function test_una_receta_no_puede_tardar_mas_que_el_tiempo_del_hogar(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['comida']['opciones'][0]['duracion_momento_min'] = 31;

        $errores = $this->menu->validarEntrega($datos);

        $this->assertCount(1, $errores);
        $this->assertStringContainsString('tarda 31 min', $errores[0]);
    }

    public function test_el_numero_de_opciones_tiene_que_estar_entre_el_minimo_y_el_maximo(): void
    {
        $uno = $this->entregaValida();
        $uno['dias'][0]['comida']['opciones'] = [$uno['dias'][0]['comida']['opciones'][0]];
        $cuatro = $this->entregaValida();
        $o = $cuatro['dias'][0]['cena']['opciones'];
        $cuatro['dias'][0]['cena']['opciones'] = [...$o, ...$o];

        $this->assertStringContainsString('hay 1 opciones', $this->menu->validarEntrega($uno)[0]);
        $this->assertStringContainsString('hay 4 opciones', $this->menu->validarEntrega($cuatro)[0]);
    }

    public function test_si_el_hogar_no_cocina_un_momento_no_puede_haber_opciones(): void
    {
        $this->hogar->disponibilidad()->where('dia_semana', 3)->update(['minutos_cena' => null]);
        $this->menu->update(['encargo' => $this->menu->fresh()->construirEncargo()]);
        $datos = $this->entregaValida();
        $this->assertSame([], $this->menu->fresh()->validarEntrega($datos));

        $datos['dias'][2]['cena']['opciones'] = [$datos['dias'][0]['cena']['opciones'][0], $datos['dias'][0]['cena']['opciones'][1]];

        $this->assertStringContainsString('no cocina en ese momento', $this->menu->fresh()->validarEntrega($datos)[0]);
    }

    public function test_las_fechas_tienen_que_ser_las_de_la_semana(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][6]['fecha'] = '2026-11-09';

        $this->assertStringContainsString('no coinciden con la semana', $this->menu->validarEntrega($datos)[0]);
    }

    public function test_categorias_e_ingredientes_son_obligatorios_y_validos(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['comida']['opciones'][0]['categorias'] = ['marciano'];
        $datos['dias'][0]['comida']['opciones'][0]['ingredientes'] = [];

        $errores = $this->menu->validarEntrega($datos);

        $this->assertCount(2, $errores);
    }

    public function test_sin_lista_de_dias_se_rechaza(): void
    {
        $this->assertSame(['Falta la lista «dias» en la entrega.'], $this->menu->validarEntrega(['entrega' => ['completo' => true]]));
    }
}
