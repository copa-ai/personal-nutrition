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
        $datos['explicaciones'] = [['perfil_id' => $this->marta->id, 'texto' => 'Hola.']];

        try {
            $this->menu->importarEntrega($datos);
            $this->fail('Debía lanzar una excepción.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('una explicación por perfil', $e->getMessage());
        }

        $this->assertNull($this->menu->fresh()->contenido);
    }

    public function test_hace_falta_la_version_2(): void
    {
        $datos = $this->entregaValida();
        $datos['entrega']['version'] = 1;

        $this->assertStringContainsString('versión 2', $this->menu->validarEntrega($datos)[0]);
    }

    public function test_cada_dia_necesita_una_entrada_individual_por_perfil_tambien_el_fin_de_semana(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][5]['individuales'] = [$datos['dias'][5]['individuales'][0]];

        $errores = $this->menu->validarEntrega($datos);

        $this->assertCount(1, $errores);
        $this->assertStringContainsString('2026-11-07 · individuales', $errores[0]);
    }

    public function test_cada_toma_individual_necesita_titulo_ingredientes_y_duraciones(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['individuales'][0]['desayuno']['titulo'] = ' ';
        $datos['dias'][0]['individuales'][0]['almuerzo']['ingredientes'] = [];
        $datos['dias'][0]['individuales'][1]['merienda']['duracion_previa_min'] = -1;
        unset($datos['dias'][0]['individuales'][1]['desayuno']);

        $errores = $this->menu->validarEntrega($datos);

        $this->assertCount(4, $errores);
        $this->assertStringContainsString('2026-11-02 · perfil '.$this->marta->id.' · desayuno', $errores[0]);
    }

    public function test_una_toma_individual_respeta_el_tiempo_del_hogar_solo_si_hay_dato(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['individuales'][0]['desayuno']['duracion_momento_min'] = 11;
        $this->assertStringContainsString('tarda 11 min', $this->menu->validarEntrega($datos)[0]);

        $this->hogar->disponibilidad()->where('dia_semana', 1)->update(['minutos_desayuno' => null]);
        $this->menu->update(['encargo' => $this->menu->fresh()->construirEncargo()]);
        $this->assertSame([], $this->menu->fresh()->validarEntrega($datos));
    }

    public function test_un_ingrediente_generico_es_valido(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['individuales'][0]['merienda']['ingredientes'] = [['nombre' => 'fruta', 'cantidad' => 1, 'unidad' => 'pieza', 'en_stock' => false]];

        $this->assertSame([], $this->menu->validarEntrega($datos));
    }

    public function test_las_categorias_de_la_opcion_son_las_de_la_toma_sin_importar_el_orden(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['comida']['categorias'] = ['verdura', 'cereal'];
        $datos['dias'][0]['comida']['opciones'][0]['categorias'] = ['cereal', 'verdura'];
        $datos['dias'][0]['comida']['opciones'][1]['categorias'] = ['verdura', 'cereal'];
        $this->assertSame([], $this->menu->validarEntrega($datos));

        $datos['dias'][0]['comida']['opciones'][1]['categorias'] = ['verdura'];
        $errores = $this->menu->validarEntrega($datos);
        $this->assertCount(1, $errores);
        $this->assertStringContainsString('mismas categorías que la toma', $errores[0]);
    }

    public function test_las_categorias_de_la_toma_son_obligatorias_y_validas_si_se_cocina(): void
    {
        $datos = $this->entregaValida();
        $datos['dias'][0]['cena']['categorias'] = [];
        $this->assertStringContainsString('lista no vacía', $this->menu->validarEntrega($datos)[0]);

        $datos = $this->entregaValida();
        $datos['dias'][0]['cena']['categorias'] = ['marciano'];
        $this->assertStringContainsString('lista no vacía', $this->menu->validarEntrega($datos)[0]);

        $datos = $this->entregaValida();
        unset($datos['dias'][0]['cena']['categorias']);
        $this->assertStringContainsString('falta la lista «categorias»', $this->menu->validarEntrega($datos)[0]);
    }

    public function test_cada_perfil_tiene_una_explicacion_con_texto(): void
    {
        $datos = $this->entregaValida();
        $datos['explicaciones'][1]['texto'] = ' ';

        $errores = $this->menu->validarEntrega($datos);

        $this->assertSame(['Cada explicación necesita «texto».'], $errores);
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

        $datos = $this->entregaValida();
        $datos['dias'][2]['cena']['categorias'] = ['verdura'];
        $this->assertStringContainsString('«categorias» debe ir vacío', $this->menu->fresh()->validarEntrega($datos)[0]);
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
