<?php

namespace Tests\Feature;

use App\Enums\EstadoMenu;
use App\Filament\Resources\Menus\Pages\ViewMenu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreaHogar;
use Tests\TestCase;

class MenuPdfTest extends TestCase
{
    use CreaHogar, RefreshDatabase;

    private const DIA = '2026-11-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearHogar();
    }

    private function ingrediente(string $nombre, ?float $cantidad = 1, string $unidad = 'ud', ?bool $enStock = null): array
    {
        return array_filter(['nombre' => $nombre, 'cantidad' => $cantidad, 'unidad' => $unidad, 'en_stock' => $enStock], fn ($v) => $v !== null);
    }

    /**
     * Importa un menú cuyo primer día tiene ingredientes conocidos; el resto de tomas (también las individuales) llevan solo «agua» (en casa), para no mezclarse.
     * Comida del lunes: opción 0 = Arroz, opción 1 = Brócoli.
     */
    private function importarMenu(array $ingredientesPorOpcion = []): void
    {
        $this->menu->update(['encargo' => $this->menu->construirEncargo(), 'estado' => EstadoMenu::Terminado]);
        $entrega = $this->entregaValida();
        foreach ($entrega['dias'] as $d => &$dia) {
            foreach (['comida', 'cena'] as $momento) {
                foreach ($dia[$momento]['opciones'] as $i => &$opcion) {
                    $opcion['ingredientes'] = $d === 0 && $momento === 'comida' ? $ingredientesPorOpcion[$i] : [$this->ingrediente('agua', 1, 'l', true)];
                }
            }
            foreach ($dia['individuales'] as &$ind) {
                foreach (['desayuno', 'almuerzo', 'merienda'] as $toma) {
                    $ind[$toma]['ingredientes'] = [$this->ingrediente('agua', 1, 'l', true)];
                }
            }
            unset($ind);
        }
        unset($dia, $opcion);
        $entrega['dias'][0]['comida']['opciones'][0]['titulo'] = 'Arroz con tomate';
        $entrega['dias'][0]['comida']['opciones'][1]['titulo'] = 'Brócoli al vapor';
        $this->menu->importarEntrega($entrega);
    }

    private function compraPorNombre(array $lista): array
    {
        return collect($lista)->mapWithKeys(fn (array $i): array => [mb_strtolower($i['nombre']) => [$i['cantidad'], $i['unidad']]])->all();
    }

    private function ver(): Testable
    {
        $this->actingAs(User::factory()->create());

        return Livewire::test(ViewMenu::class, ['record' => $this->menu->getRouteKey()]);
    }

    // ---- elegir opciones ----

    public function test_sin_elegir_se_usa_la_primera_opcion(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz', 300, 'g')], [$this->ingrediente('brócoli')]]);

        $this->assertSame(0, $this->menu->indiceElegido(self::DIA, 'comida'));
        $this->assertSame('Arroz con tomate', $this->menu->opcionElegida(self::DIA, 'comida')['titulo']);
        $this->assertSame(0, $this->menu->totalElegidas());
        $this->assertSame(14, $this->menu->totalTomas());
    }

    public function test_elegir_una_opcion_se_guarda_y_cuenta(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        $this->menu->elegirOpcion(self::DIA, 'comida', 1);
        $this->menu->elegirOpcion(self::DIA, 'cena', 1);

        $m = $this->menu->fresh();
        $this->assertSame('Brócoli al vapor', $m->opcionElegida(self::DIA, 'comida')['titulo']);
        $this->assertEquals(['2026-11-02/comida' => 1, '2026-11-02/cena' => 1], $m->selecciones);
        $this->assertSame(2, $m->totalElegidas());
    }

    public function test_se_puede_volver_a_elegir_la_primera(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $this->menu->elegirOpcion(self::DIA, 'comida', 1);

        $this->menu->elegirOpcion(self::DIA, 'comida', 0);

        $this->assertSame(0, $this->menu->fresh()->indiceElegido(self::DIA, 'comida'));
    }

    public function test_una_opcion_o_una_toma_que_no_existe_se_rechaza(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        foreach ([[self::DIA, 'comida', 2], [self::DIA, 'comida', -1], [self::DIA, 'desayuno', 0], ['2026-12-25', 'comida', 0]] as [$fecha, $momento, $indice]) {
            try {
                $this->menu->elegirOpcion($fecha, $momento, $indice);
                $this->fail("Debía rechazar {$fecha} {$momento} {$indice}.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no existe', $e->getMessage());
            }
        }
        $this->assertNull($this->menu->fresh()->selecciones);
    }

    public function test_una_eleccion_que_ya_no_es_valida_vuelve_a_la_primera(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $this->menu->update(['selecciones' => ['2026-11-02/comida' => 7, '2026-11-03/cena' => 'x', 'basura' => 1]]);

        $this->assertSame(0, $this->menu->indiceElegido(self::DIA, 'comida'));
        $this->assertSame(0, $this->menu->indiceElegido('2026-11-03', 'cena'));
        $this->assertSame(0, $this->menu->totalElegidas());
    }

    public function test_reimportar_el_resultado_reinicia_las_elecciones(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $this->menu->elegirOpcion(self::DIA, 'comida', 1);

        $this->menu->fresh()->importarEntrega($this->entregaValida());

        $this->assertNull($this->menu->fresh()->selecciones);
    }

    // ---- lista de la compra ----

    public function test_la_compra_suma_por_nombre_y_unidad_sin_distinguir_mayusculas(): void
    {
        $this->importarMenu([[$this->ingrediente('Arroz', 300, 'g', true), $this->ingrediente('arroz', 200, 'g', true), $this->ingrediente('arroz', 1, 'kg', true)], [$this->ingrediente('agua', 1, 'l', true)]]);

        $arroz = collect($this->menu->listaCompra()['en_casa'])->filter(fn (array $i): bool => mb_strtolower($i['nombre']) === 'arroz');

        $this->assertCount(2, $arroz);
        $this->assertSame(500.0, $arroz->firstWhere('unidad', 'g')['cantidad']);
        $this->assertSame(1.0, $arroz->firstWhere('unidad', 'kg')['cantidad']);
    }

    public function test_la_compra_separa_lo_que_hay_en_casa_de_lo_que_hay_que_comprar(): void
    {
        $this->importarMenu([[
            $this->ingrediente('arroz', 300, 'g', true), $this->ingrediente('tomate', 200, 'g', false), $this->ingrediente('sal', null, '', null), $this->ingrediente('limón', 1, 'ud', true),
        ], [$this->ingrediente('agua', 1, 'l', true)]]);
        $this->menu->update(['selecciones' => null]);

        $lista = $this->menu->listaCompra();

        $this->assertEqualsCanonicalizing(['tomate', 'sal'], array_column($lista['comprar'], 'nombre'));
        $this->assertEqualsCanonicalizing(['arroz', 'limón', 'agua'], array_column($lista['en_casa'], 'nombre'));
        $this->assertNull($this->compraPorNombre($lista['comprar'])['sal'][0]);
    }

    public function test_si_un_ingrediente_esta_en_casa_en_una_receta_y_no_en_otra_se_compra(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz', 300, 'g', true)], [$this->ingrediente('agua', 1, 'l', true)]]);
        $entrega = $this->menu->fresh()->contenido;
        $entrega['dias'][1]['comida']['opciones'][0]['ingredientes'] = [$this->ingrediente('arroz', 100, 'g', false)];
        $this->menu->update(['contenido' => $entrega]);

        $arroz = $this->compraPorNombre($this->menu->fresh()->listaCompra()['comprar'])['arroz'];

        $this->assertSame([400.0, 'g'], $arroz);
    }

    public function test_la_compra_solo_cuenta_las_opciones_elegidas(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz', 300, 'g', false)], [$this->ingrediente('brócoli', 1, 'ud', false)]]);

        $porDefecto = array_column($this->menu->listaCompra()['comprar'], 'nombre');
        $this->assertContains('arroz', $porDefecto);
        $this->assertNotContains('brócoli', $porDefecto);

        $this->menu->elegirOpcion(self::DIA, 'comida', 1);
        $eligiendo = array_column($this->menu->fresh()->listaCompra()['comprar'], 'nombre');
        $this->assertContains('brócoli', $eligiendo);
        $this->assertNotContains('arroz', $eligiendo);
    }

    public function test_la_compra_se_ordena_sin_distinguir_tildes(): void
    {
        $this->importarMenu([[$this->ingrediente('Zanahoria', 1, 'ud', false), $this->ingrediente('árbol de té', 1, 'ud', false), $this->ingrediente('berenjena', 1, 'ud', false)], [$this->ingrediente('agua', 1, 'l', true)]]);

        $this->assertSame(['árbol de té', 'berenjena', 'Zanahoria'], array_column($this->menu->listaCompra()['comprar'], 'nombre'));
    }

    // ---- el documento ----

    public function test_la_vista_del_pdf_lleva_solo_la_opcion_elegida_y_las_tomas_individuales(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz', 300, 'g', true)], [$this->ingrediente('brócoli', 1, 'ud', false)]]);
        $this->menu->elegirOpcion(self::DIA, 'comida', 1);

        $html = view('pdf.menu', ['d' => $this->menu->fresh()->datosDocumento()])->render();

        $this->assertStringContainsString('Brócoli al vapor', $html);
        $this->assertStringNotContainsString('Arroz con tomate', $html);
        $this->assertStringContainsString('Marta', $html);
        $this->assertStringContainsString('Javier', $html);
        $this->assertStringContainsString('sin lácteos', $html);
        $this->assertStringContainsString('Tostada', $html);
        $this->assertStringContainsString('Menú pensado para Marta.', $html);
        $this->assertStringContainsString('La compra', $html);
        $this->assertStringContainsString('no sustituye el consejo de un profesional sanitario', $html);
        $this->assertStringContainsString('Menú de la semana · del 2 al 8 de noviembre', $html);
    }

    public function test_el_pdf_pone_la_compra_antes_que_los_dias(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        $html = view('pdf.menu', ['d' => $this->menu->fresh()->datosDocumento()])->render();

        $compra = strpos($html, '<h2>La compra</h2>');
        $semana = strpos($html, '<h2>La semana</h2>');
        $this->assertNotFalse($compra);
        $this->assertNotFalse($semana);
        $this->assertLessThan($semana, $compra);
        $this->assertLessThan($semana, strpos($html, '<h2>Cada persona</h2>'));
    }

    public function test_la_compra_suma_las_tomas_individuales_y_deja_el_ingrediente_generico(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $contenido = $this->menu->fresh()->contenido;
        $contenido['dias'][0]['individuales'][0]['merienda']['ingredientes'] = [['nombre' => 'fruta', 'cantidad' => 1, 'unidad' => 'pieza', 'en_stock' => false]];
        $contenido['dias'][0]['individuales'][1]['merienda']['ingredientes'] = [['nombre' => 'Fruta', 'cantidad' => 2, 'unidad' => 'pieza', 'en_stock' => false]];
        $contenido['dias'][1]['individuales'][0]['desayuno']['ingredientes'] = [['nombre' => 'avena', 'cantidad' => 40, 'unidad' => 'g', 'en_stock' => true]];
        $this->menu->update(['contenido' => $contenido]);

        $lista = $this->menu->fresh()->listaCompra();

        $this->assertSame([3.0, 'pieza'], $this->compraPorNombre($lista['comprar'])['fruta']);
        $this->assertContains('avena', array_column($lista['en_casa'], 'nombre'));
    }

    public function test_los_contadores_de_tomas_no_cuentan_las_individuales(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        $this->assertSame(14, $this->menu->totalTomas());
        $this->assertCount(2, $this->menu->tomas()[0]['individuales']);
        $this->assertCount(2, $this->menu->explicaciones());
    }

    public function test_el_texto_del_menu_se_escapa_en_el_pdf(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $contenido = $this->menu->fresh()->contenido;
        $contenido['dias'][0]['comida']['opciones'][0]['titulo'] = '<script type="text/php">exit;</script><b>negrita</b>';
        $this->menu->update(['contenido' => $contenido]);

        $html = view('pdf.menu', ['d' => $this->menu->fresh()->datosDocumento()])->render();

        $this->assertStringNotContainsString('<script type="text/php">', $html);
        $this->assertStringContainsString('&lt;script type=&quot;text/php&quot;&gt;', $html);
    }

    public function test_un_dia_sin_cocinar_sale_como_no_se_cocina(): void
    {
        $this->hogar->disponibilidad()->where('dia_semana', 1)->update(['minutos_cena' => null]);
        $this->menu->update(['encargo' => $this->menu->fresh()->construirEncargo(), 'estado' => EstadoMenu::Terminado]);
        $entrega = $this->entregaValida();
        $this->menu->importarEntrega($entrega);

        $datos = $this->menu->fresh()->datosDocumento();

        $this->assertNull($datos['dias'][0]['tomas']['cena']['opcion']);
        $this->assertSame(13, $datos['tomas']);
        $this->assertStringContainsString('No se cocina', view('pdf.menu', ['d' => $datos])->render());
    }

    public function test_el_pdf_se_genera_de_verdad(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz', 300, 'g', true)], [$this->ingrediente('brócoli')]]);

        $pdf = $this->menu->fresh()->generarPdf();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    public function test_el_nombre_del_archivo_es_un_slug(): void
    {
        $this->menu->update(['nombre' => 'Menú de la semana del 2 de noviembre']);

        $this->assertSame('menu-de-la-semana-del-2-de-noviembre.pdf', $this->menu->fresh()->nombreArchivoPdf());
    }

    // ---- la ficha ----

    public function test_la_ficha_muestra_los_botones_de_elegir_y_la_opcion_elegida(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $this->menu->elegirOpcion(self::DIA, 'comida', 1);

        $this->ver()->assertSee('Elige una opción por toma')->assertSee('Tu elección')->assertSee('Por defecto')->assertSee('1 de 14');
    }

    public function test_elegir_desde_la_ficha_guarda_la_eleccion_sin_avisos(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        $this->ver()->call('elegirOpcion', self::DIA, 'cena', 1)->assertNotNotified();

        $this->assertSame(1, $this->menu->fresh()->indiceElegido(self::DIA, 'cena'));
    }

    public function test_elegir_una_opcion_inexistente_avisa(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        $this->ver()->call('elegirOpcion', self::DIA, 'cena', 9)->assertNotified('No se pudo elegir esa opción');

        $this->assertNull($this->menu->fresh()->selecciones);
    }

    public function test_mientras_se_genera_no_se_puede_elegir_ni_descargar(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);
        $this->menu->update(['estado' => EstadoMenu::EnMarcha]);

        $this->ver()->call('elegirOpcion', self::DIA, 'cena', 1)->assertActionHidden('pdf')->assertDontSee('Elige una opción por toma');

        $this->assertNull($this->menu->fresh()->selecciones);
    }

    public function test_el_boton_pdf_solo_aparece_con_un_menu_importado(): void
    {
        $this->ver()->assertActionHidden('pdf');   // borrador, sin contenido
    }

    public function test_descargar_el_pdf_devuelve_el_archivo(): void
    {
        $this->importarMenu([[$this->ingrediente('arroz')], [$this->ingrediente('brócoli')]]);

        $this->ver()->assertActionVisible('pdf')->callAction('pdf')->assertFileDownloaded('menu-de-la-semana.pdf');
    }
}
