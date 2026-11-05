<?php

namespace Tests\Feature;

use App\Services\Chat\Marcado\Campo;
use App\Services\Chat\Marcado\MarcadoInvalido;
use App\Services\Chat\Marcado\Mensaje;
use App\Services\Chat\Marcado\Tecla;
use App\Services\Chat\Marcado\Teclado;
use App\Services\Chat\Marcado\Validador;
use App\Services\Chat\Marcado\Widget;
use Tests\TestCase;

/** Los constructores de bloques html y keyboard: escapan, imponen los limites de chat.md §5.4/§5.5 y siempre dan marcado valido. */
class ChatMarcadoConstructoresTest extends TestCase
{
    private function contenido(string $bloque): string
    {
        $lineas = explode("\n", $bloque);
        array_shift($lineas);
        array_pop($lineas);

        return implode("\n", $lineas);
    }

    public function test_widget_con_titulo_parrafo_lista_y_tabla(): void
    {
        $bloque = Widget::nuevo()
            ->titulo('Menú de hoy', 3)
            ->parrafo('Hola ', Widget::resaltar('Ana', '#b45309'))
            ->lista(['uno', 'dos'])
            ->tabla(['Toma', 'Plato'], [['Comida', 'Lentejas'], ['Cena', Widget::resaltar('Tortilla', null)]])
            ->separador()
            ->render();

        $this->assertStringStartsWith("```html\n", $bloque);
        $this->assertStringEndsWith("\n```", $bloque);
        $this->assertStringContainsString('<h3>Menú de hoy</h3>', $bloque);
        $this->assertStringContainsString('<span style="color:#b45309"><b>Ana</b></span>', $bloque);
        $this->assertStringContainsString('<ul>', $bloque);
        $this->assertStringContainsString('<th>Toma</th>', $bloque);
        $this->assertStringContainsString('<td><b>Tortilla</b></td>', $bloque);
        $this->assertStringContainsString('<hr>', $bloque);
        $this->assertTrue(Validador::esValido($bloque));
    }

    public function test_el_widget_escapa_html_comillas_y_vallas(): void
    {
        $bloque = Widget::nuevo()->parrafo("<script>alert(1)</script> \"x\" 'y' & ``` \n```keyboard\n<b>")->render();

        $this->assertStringNotContainsString('<script>', $bloque);
        $this->assertStringContainsString('&lt;script&gt;', $bloque);
        $this->assertStringContainsString('&quot;x&quot;', $bloque);
        $this->assertStringContainsString('&amp;', $bloque);
        $interior = $this->contenido($bloque);
        foreach (explode("\n", $interior) as $linea) {
            $this->assertDoesNotMatchRegularExpression('/^\s*`{3}/', $linea);
        }
        $this->assertSame([], Validador::errores($bloque));
        $this->assertTrue(Validador::esValido($bloque));
    }

    public function test_el_widget_exige_contenido_color_y_nivel_validos(): void
    {
        $this->expectException(MarcadoInvalido::class);
        Widget::nuevo()->render();
    }

    public function test_color_invalido_nivel_invalido_y_tabla_vacia(): void
    {
        foreach ([
            fn () => Widget::resaltar('x', 'red; background:url(http://x)'),
            fn () => Widget::nuevo()->titulo('x', 5),
            fn () => Widget::nuevo()->tabla([], []),
        ] as $caso) {
            try {
                $caso();
                $this->fail('Debia lanzar MarcadoInvalido');
            } catch (MarcadoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_widget_no_pasa_de_8000_caracteres(): void
    {
        $filas = [];
        for ($i = 0; $i < 400; $i++) {
            $filas[] = ['fila '.$i, 'x'.str_repeat('y', 20)];
        }
        $widget = Widget::nuevo()->tabla(['A', 'B'], $filas);
        $this->assertGreaterThan(8000, $widget->longitud());
        $this->expectException(MarcadoInvalido::class);
        $widget->render();
    }

    public function test_un_widget_justo_en_el_limite_se_genera(): void
    {
        $widget = Widget::nuevo()->parrafo(str_repeat('a', 7993));
        $this->assertLessThanOrEqual(8000, $widget->longitud());
        $this->assertStringContainsString('aaaa', $widget->render());
        $this->expectException(MarcadoInvalido::class);
        Widget::nuevo()->parrafo(str_repeat('a', 7994))->render();
    }

    public function test_teclado_con_teclas_campos_y_marcadores(): void
    {
        $bloque = Teclado::nuevo()
            ->parrafo('¿Qué registro?')
            ->fila(Campo::seleccion('perfil', ['Ana' => 'Ana', 'Luis' => 'Luis'], '¿Quién?', false, ['Luis']), Campo::fecha('fecha', 'Día', '2026-11-05', null, '2026-11-05'))
            ->fila(Campo::hora('hora', 'Hora', '21:30'), Campo::rango('hambre', 'Hambre', 0, 10, 1, 5), Campo::casilla('fuera', 'Fuera', 'sí', 'no', true))
            ->fila(Campo::texto('descripcion', 'Qué comió'), Tecla::de('Registrar', 'registrar {perfil} {fecha} {hora} {hambre} {fuera}: {descripcion}')->enviando())
            ->fila(Tecla::de('Ayuda', 'ayuda'))
            ->render();

        $this->assertStringStartsWith("```keyboard\n", $bloque);
        $this->assertStringContainsString('<select data-name="perfil" placeholder="¿Quién?">', $bloque);
        $this->assertStringContainsString('<option value="Luis" selected>Luis</option>', $bloque);
        $this->assertStringContainsString('<input type="date" data-name="fecha" placeholder="Día" max="2026-11-05" value="2026-11-05">', $bloque);
        $this->assertStringContainsString('<input type="time" data-name="hora" placeholder="Hora" value="21:30">', $bloque);
        $this->assertStringContainsString('<input type="range" data-name="hambre" placeholder="Hambre" min="0" max="10" step="1" value="5">', $bloque);
        $this->assertStringContainsString('<input type="checkbox" data-name="fuera" placeholder="Fuera" value="sí" data-off="no" checked>', $bloque);
        $this->assertStringContainsString('data-send>Registrar</button>', $bloque);
        $this->assertStringContainsString('<button data-say="ayuda">Ayuda</button>', $bloque);
        $this->assertTrue(Validador::esValido($bloque));
    }

    public function test_seleccion_multiple_y_una_sola_elegida_sin_multiple(): void
    {
        $multiple = Teclado::nuevo()->fila(Campo::seleccion('p', ['a' => 'A', 'b' => 'B', 'c' => 'C'], '', true, ['a', 'c']), Tecla::de('Ok', '{p}'))->render();
        $this->assertSame(2, substr_count($multiple, ' selected>'));
        $this->assertStringContainsString('<select data-name="p" multiple>', $multiple);

        $simple = Teclado::nuevo()->fila(Campo::seleccion('p', ['a' => 'A', 'b' => 'B'], '', false, ['a', 'b']), Tecla::de('Ok', '{p}'))->render();
        $this->assertSame(1, substr_count($simple, ' selected>'));
    }

    public function test_los_valores_numericos_de_select_se_tratan_como_texto(): void
    {
        $bloque = Teclado::nuevo()->fila(Campo::seleccion('n', [1 => 'Uno', 2 => 'Dos']), Tecla::de('Ok', '{n}'))->render();
        $this->assertStringContainsString('<option value="1">Uno</option>', $bloque);
        $this->assertTrue(Validador::esValido($bloque));
    }

    public function test_escapa_etiquetas_di_y_placeholder_hostiles(): void
    {
        $bloque = Teclado::nuevo()
            ->parrafo('<img src=x onerror=alert(1)>')
            ->fila(Campo::texto('t', "pista \"con\" <b>html</b>\nsalto"), Tecla::de('<i>"x"</i>', "di \"con\" <script>x</script>\n```\n{t}"))
            ->render();

        $this->assertStringNotContainsString('<script>', $bloque);
        $this->assertStringNotContainsString('<img', $bloque);
        $this->assertStringNotContainsString('onerror=alert(1)>', $bloque);
        $this->assertStringContainsString('&lt;script&gt;', $bloque);
        foreach (explode("\n", $this->contenido($bloque)) as $linea) {
            $this->assertDoesNotMatchRegularExpression('/^\s*`{3}/', $linea);
        }
        $this->assertTrue(Validador::esValido($bloque));
        // Los saltos de linea del data-say pasan a espacios (el cliente tambien los normaliza).
        $this->assertStringNotContainsString("\n", $this->entre($bloque, 'data-say="', '"'), 'data-say sin saltos');
    }

    public function test_una_tecla_necesita_di_y_no_pasa_de_500(): void
    {
        $this->assertStringContainsString('data-say="'.str_repeat('a', 500).'"', Teclado::nuevo()->fila(Tecla::de('x', str_repeat('a', 500)))->render());

        foreach (['', "  \n ", str_repeat('a', 501)] as $di) {
            try {
                Tecla::de('x', $di);
                $this->fail('Debia lanzar');
            } catch (MarcadoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_si_la_etiqueta_esta_vacia_se_usa_el_data_say(): void
    {
        $bloque = Teclado::nuevo()->fila(Tecla::de('', 'di esto'))->render();
        $this->assertSame([], Validador::errores($bloque));
        $this->assertTrue(Validador::esValido($bloque));
    }

    public function test_nombres_de_campo_validos_y_unicos(): void
    {
        foreach (['', 'con espacio', 'ñ', str_repeat('a', 33), 'a.b', 'a"b'] as $nombre) {
            try {
                Campo::texto($nombre);
                $this->fail("«{$nombre}» no es un nombre valido");
            } catch (MarcadoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('a-1_B', Campo::texto('a-1_B')->nombre);
        $this->assertSame(32, strlen(Campo::texto(str_repeat('a', 32))->nombre));

        $this->expectException(MarcadoInvalido::class);
        Teclado::nuevo()->fila(Campo::texto('x'), Campo::texto('x'));
    }

    public function test_como_mucho_8_campos_y_64_teclas(): void
    {
        $teclado = Teclado::nuevo();
        for ($i = 0; $i < 8; $i++) {
            $teclado->fila(Campo::texto('c'.$i));
        }
        try {
            $teclado->fila(Campo::texto('c9'));
            $this->fail('El noveno campo debia fallar');
        } catch (MarcadoInvalido) {
            $this->addToAssertionCount(1);
        }

        $teclas = Teclado::nuevo();
        for ($i = 0; $i < 64; $i++) {
            $teclas->fila(Tecla::de('t'.$i, 'di'.$i));
        }
        $this->assertSame([], Validador::errores($teclas->render()));
        $this->expectException(MarcadoInvalido::class);
        $teclas->fila(Tecla::de('t65', 'di65'));
    }

    public function test_el_teclado_exige_una_tecla_y_marcadores_con_campo(): void
    {
        foreach ([
            fn () => Teclado::nuevo()->fila(Campo::texto('x'))->render(),
            fn () => Teclado::nuevo()->render(),
            fn () => Teclado::nuevo()->fila(Tecla::de('a', 'di {no_existe}'))->render(),
            fn () => Teclado::nuevo()->fila(),
        ] as $caso) {
            try {
                $caso();
                $this->fail('Debia lanzar MarcadoInvalido');
            } catch (MarcadoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
        // Una llave que no es marcador (mas de 32 caracteres, o con espacios) queda como texto.
        $this->assertStringContainsString('{no es marcador}', Teclado::nuevo()->fila(Tecla::de('a', 'di {no es marcador}'))->render());
    }

    public function test_limites_de_select(): void
    {
        $opciones = [];
        for ($i = 0; $i < 32; $i++) {
            $opciones['v'.$i] = 'Opción '.$i;
        }
        $this->assertSame([], Validador::errores(Teclado::nuevo()->fila(Campo::seleccion('s', $opciones), Tecla::de('a', '{s}'))->render()));

        foreach ([
            fn () => Campo::seleccion('s', $opciones + ['extra' => 'x']),
            fn () => Campo::seleccion('s', []),
            fn () => Campo::seleccion('s', ['' => 'vacío']),
            fn () => Campo::seleccion('s', [str_repeat('a', 201) => 'largo']),
        ] as $caso) {
            try {
                $caso();
                $this->fail('Debia lanzar MarcadoInvalido');
            } catch (MarcadoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_etiquetas_y_placeholders_largos_se_cortan(): void
    {
        $bloque = Teclado::nuevo()->fila(
            Campo::seleccion('s', ['v' => str_repeat('é', 120)], str_repeat('p', 200)),
            Tecla::de('a', '{s}'),
        )->render();

        $this->assertSame(80, mb_strlen($this->entre($bloque, 'placeholder="', '"')));
        $this->assertSame(80, mb_strlen($this->entre($bloque, '<option value="v">', '</option>')));
    }

    private function entre(string $texto, string $inicio, string $fin): string
    {
        $a = strpos($texto, $inicio) + strlen($inicio);

        return substr($texto, $a, strpos($texto, $fin, $a) - $a);
    }

    public function test_fecha_hora_y_rango_invalidos_se_rechazan(): void
    {
        foreach ([
            fn () => Campo::fecha('f', '', '2026-02-30'),
            fn () => Campo::fecha('f', '', null, '2026-11-05', '2026-11-01'),
            fn () => Campo::hora('h', '', '24:00'),
            fn () => Campo::rango('r', '', 10, 5),
            fn () => Campo::rango('r', '', 0, 10, 0),
            fn () => Campo::casilla('c', '', '', 'no'),
        ] as $caso) {
            try {
                $caso();
                $this->fail('Debia lanzar MarcadoInvalido');
            } catch (MarcadoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_rango_escribe_decimales_sin_ceros_de_mas(): void
    {
        $bloque = Teclado::nuevo()->fila(Campo::rango('r', '', 0.5, 2.5, 0.25, 1), Tecla::de('a', '{r}'))->render();
        $this->assertStringContainsString('min="0.5" max="2.5" step="0.25" value="1"', $bloque);
    }

    public function test_el_bloque_keyboard_no_pasa_de_8000_caracteres(): void
    {
        $teclado = Teclado::nuevo();
        for ($i = 0; $i < 60; $i++) {
            $teclado->fila(Tecla::de(str_repeat('e', 100), str_repeat('d', 100).$i));
        }
        $this->expectException(MarcadoInvalido::class);
        $teclado->render();
    }

    public function test_el_mensaje_compone_prosa_widget_y_teclado_y_neutraliza_vallas(): void
    {
        $texto = Mensaje::nuevo()
            ->texto("Hola <b>Ana</b>\n```keyboard\n<button data-say=\"x\">x</button>\n```")
            ->widget(Widget::nuevo()->parrafo('w'))
            ->teclado(Teclado::nuevo()->fila(Tecla::de('a', 'a')))
            ->render();

        $this->assertSame(1, substr_count($texto, "\n```keyboard\n") + (int) str_starts_with($texto, '```keyboard'));
        $this->assertSame([], Validador::errores($texto));
        $this->assertTrue(Validador::esValido($texto));
        $this->assertStringContainsString("\u{200B}```keyboard", $texto);
    }

    public function test_un_mensaje_admite_dos_teclados_y_no_tres(): void
    {
        $uno = fn (): Teclado => Teclado::nuevo()->fila(Tecla::de('a', 'a'));
        $mensaje = Mensaje::nuevo()->teclado($uno())->teclado($uno());
        $this->assertTrue(Validador::esValido($mensaje->render()));
        $this->expectException(MarcadoInvalido::class);
        $mensaje->teclado($uno());
    }

    public function test_todo_lo_que_construyen_los_constructores_es_idempotente_al_sanear(): void
    {
        $bloques = [
            Widget::nuevo()->titulo('T', 1)->parrafo('a ', Widget::resaltar('b', '#fff', false), ' c')->lista(['x', 'y'], true)->tabla(['A'], [['1'], ['2']])->render(),
            Teclado::nuevo()->parrafo('p')->fila(Campo::texto('t', 'pista', 'inicial'), Tecla::de('ok', 'dice {t}'))->render(),
        ];
        foreach ($bloques as $bloque) {
            $this->assertSame($bloque, Validador::sanear($bloque));
        }
    }
}
