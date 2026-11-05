<?php

namespace Tests\Feature;

use App\Services\Chat\Marcado\MarcadoInvalido;
use App\Services\Chat\Marcado\Validador;
use Tests\TestCase;

/** El validador sanea lo que escribe un modelo: limpia lo no permitido y degrada a codigo lo que no se puede arreglar. */
class ChatMarcadoValidadorTest extends TestCase
{
    private function html(string $interior): string
    {
        return "```html\n{$interior}\n```";
    }

    private function teclado(string $interior): string
    {
        return "```keyboard\n{$interior}\n```";
    }

    public function test_el_texto_sin_bloques_no_se_toca(): void
    {
        $texto = "Hola **mundo**\n\n- uno\n- dos <script>no se toca fuera de un bloque</script>";
        $this->assertSame($texto, Validador::sanear($texto));
        $this->assertSame('', Validador::sanear(''));
    }

    public function test_un_bloque_valido_queda_igual(): void
    {
        $texto = "Antes\n\n".$this->html("<h3>Hoy</h3>\n<table>\n<tr>\n<th>A</th>\n<th>B</th>\n</tr>\n</table>")."\n\nDespués";
        $this->assertSame($texto, Validador::sanear($texto));
        $this->assertTrue(Validador::esValido($texto));
    }

    public function test_quita_scripts_estilos_eventos_y_atributos_no_permitidos(): void
    {
        $sucio = $this->html('<p onclick="x()" class="c" id="i" style="color:red;position:fixed;background:url(http://x/y.png)">Hola<script>alert(1)</script><style>p{}</style><iframe src="http://x"></iframe><img src="http://x/a.png" onerror="x"> mundo</p>');
        $limpio = Validador::sanear($sucio);

        $this->assertSame($this->html('<p style="color:red">Hola mundo</p>'), $limpio);
        $this->assertSame([], Validador::errores($sucio));
        $this->assertFalse(Validador::esValido($sucio));
    }

    public function test_los_estilos_solo_dejan_propiedades_y_valores_seguros(): void
    {
        $limpio = Validador::sanear($this->html('<p style="color: #fff; font-weight:bold; width:expression(alert(1)); background-color:url(x); text-align:center">x</p>'));
        $this->assertSame($this->html('<p style="color:#fff;font-weight:bold;text-align:center">x</p>'), $limpio);

        $sinEstilo = Validador::sanear($this->html('<p style="behavior:url(x)">x</p>'));
        $this->assertSame($this->html('<p>x</p>'), $sinEstilo);
    }

    public function test_enlaces_solo_http_https_y_mailto(): void
    {
        $limpio = Validador::sanear($this->html('<p><a href="https://ejemplo.org/x?a=1&b=2">ok</a> <a href="javascript:alert(1)">mal</a> <a href=" data:text/html,x">mal2</a> <a href="mailto:a@b.c">c</a></p>'));
        $this->assertStringContainsString('<a href="https://ejemplo.org/x?a=1&amp;b=2">ok</a>', $limpio);
        $this->assertStringContainsString('<a>mal</a>', $limpio);
        $this->assertStringContainsString('<a>mal2</a>', $limpio);
        $this->assertStringContainsString('<a href="mailto:a@b.c">c</a>', $limpio);
        $this->assertStringNotContainsString('javascript', $limpio);
    }

    public function test_etiquetas_desconocidas_se_desenvuelven(): void
    {
        $limpio = Validador::sanear($this->html('<section><font color="red">Hola</font> <custom-el>mundo</custom-el></section>'));
        $this->assertSame($this->html('Hola mundo'), $limpio);
    }

    public function test_el_widget_html_no_admite_controles_de_formulario(): void
    {
        $limpio = Validador::sanear($this->html('<p>Elige</p><select data-name="x"><option>a</option></select><input data-name="y"><button data-say="hola">Hola</button><p data-say="no">z</p>'));
        $this->assertSame($this->html("<p>Elige</p>\nHola\n<p>z</p>"), $limpio);
    }

    public function test_el_html_hostil_no_rompe_la_valla(): void
    {
        $limpio = Validador::sanear($this->html("<p>```</p>\n<pre>a ``` b\n  ```x\n</pre>"));
        $interior = explode("\n", $limpio);
        array_shift($interior);
        array_pop($interior);
        foreach ($interior as $linea) {
            $this->assertDoesNotMatchRegularExpression('/^\s{0,3}`{3}/', $linea);
        }
        $this->assertSame([], Validador::errores($limpio));
        $this->assertSame($limpio, Validador::sanear($limpio));
    }

    public function test_una_entidad_se_decodifica_y_vuelve_a_escapar(): void
    {
        $limpio = Validador::sanear($this->html('<p>&lt;b&gt;&amp; &#96;&#96;&#96; &quot;</p>'));
        $this->assertSame($this->html('<p>&lt;b&gt;&amp; &#96;&#96;&#96; &quot;</p>'), $limpio);
    }

    public function test_un_bloque_html_vacio_o_solo_con_cosas_prohibidas_se_degrada(): void
    {
        foreach (['', '   ', '<script>x</script>', '<!-- nada -->'] as $interior) {
            $texto = $this->html($interior);
            $this->assertSame("```text\n{$interior}\n```", Validador::sanear($texto), $interior);
            $this->assertCount(1, Validador::errores($texto));
        }
    }

    public function test_un_bloque_html_de_mas_de_8000_caracteres_se_degrada_a_codigo(): void
    {
        $grande = $this->html('<p>'.str_repeat('a', 8000).'</p>');
        $this->assertStringStartsWith("```text\n<p>aaa", Validador::sanear($grande));
        $this->assertStringContainsString('8000', Validador::errores($grande)[0]);

        $justo = $this->html('<p>'.str_repeat('a', 7993).'</p>');
        $this->assertSame($justo, Validador::sanear($justo));
    }

    public function test_un_bloque_sin_cerrar_se_degrada(): void
    {
        $texto = "Hola\n```html\n<p>sin cerrar</p>";
        $this->assertSame("Hola\n```text\n<p>sin cerrar</p>\n```", Validador::sanear($texto));
        $this->assertStringContainsString('no está cerrado', Validador::errores($texto)[0]);
    }

    public function test_otros_bloques_de_codigo_pasan_sin_tocar_aunque_lleven_html(): void
    {
        $texto = "```python\n```html\n<script>x</script>\n```\n\nfin";
        // El primer ``` cierra el bloque python; despues viene un html sin cerrar... se comprueba el comportamiento con vallas anidadas distintas.
        $codigo = "```python\nprint('<script>')\n```\ntexto";
        $this->assertSame($codigo, Validador::sanear($codigo));
        $this->assertSame($texto, Validador::sanear($texto), 'una valla con informacion no cierra el bloque python');

        $largo = "````markdown\n```html\n<script>alert(1)</script>\n```\n````";
        $this->assertSame($largo, Validador::sanear($largo));
    }

    public function test_la_info_de_la_valla_se_reconoce_sin_importar_mayusculas_y_espacios(): void
    {
        $limpio = Validador::sanear("```HTML \n<script>x</script><p>a</p>\n```");
        $this->assertSame("```html\n<p>a</p>\n```", $limpio);
        $this->assertSame("```htmlx\n<script>x</script>\n```", Validador::sanear("```htmlx\n<script>x</script>\n```"));
    }

    public function test_se_aceptan_finales_de_linea_windows(): void
    {
        $limpio = Validador::sanear("```html\r\n<p>a</p>\r\n```\r\ntexto");
        $this->assertSame("```html\n<p>a</p>\n```\ntexto", $limpio);
    }

    public function test_un_teclado_valido_pasa_y_normaliza(): void
    {
        $texto = $this->teclado('<p>¿Aceptas?</p><div><button data-say="Sí, acepto" data-send="x">Aceptar</button><button data-say="No">Rechazar</button></div>');
        $limpio = Validador::sanear($texto);
        $this->assertSame([], Validador::errores($texto));
        $this->assertStringContainsString('<button data-say="Sí, acepto" data-send>Aceptar</button>', $limpio);
        $this->assertStringContainsString('<button data-say="No">Rechazar</button>', $limpio);
        $this->assertSame($limpio, Validador::sanear($limpio));
    }

    public function test_un_boton_sin_data_say_no_es_tecla_y_pierde_el_data_send(): void
    {
        $limpio = Validador::sanear($this->teclado('<button data-send>Nada</button><button data-say="  ">Vacío</button><button data-say="vale">Vale</button>'));
        $this->assertSame($this->teclado('NadaVacío<button data-say="vale">Vale</button>'), $limpio);
    }

    public function test_un_teclado_sin_teclas_se_degrada(): void
    {
        $texto = $this->teclado('<p>Solo texto</p><input data-name="a">');
        $this->assertStringStartsWith('```text', Validador::sanear($texto));
        $this->assertStringContainsString('ninguna tecla', Validador::errores($texto)[0]);
    }

    public function test_campos_de_cada_tipo_conservan_solo_atributos_validos(): void
    {
        $texto = $this->teclado('<input data-name="d" type="date" min="2026-01-01" max="2025-01-01" value="2026-02-30" placeholder="Día" onfocus="x">'
            .'<input data-name="d2" type="DATE" min="2026-01-01" max="2026-12-31" value="2026-06-01">'
            .'<input data-name="h" type="time" value="25:00">'
            .'<input data-name="h2" type="time" value="08:05">'
            .'<input data-name="r" type="range" min="a" max="10" step="0.5" value="2">'
            .'<input data-name="c" type="checkbox" value="" data-off="nel" checked>'
            .'<input data-name="t" type="email" min="3" max="4" step="1" checked placeholder="Texto" value="pre">'
            .'<button data-say="{d}{d2}{h}{h2}{r}{c}{t}">ok</button>');
        $limpio = Validador::sanear($texto);

        $this->assertSame([], Validador::errores($texto));
        $this->assertStringContainsString('<input type="date" data-name="d" placeholder="Día">', $limpio);
        $this->assertStringContainsString('<input type="date" data-name="d2" min="2026-01-01" max="2026-12-31" value="2026-06-01">', $limpio);
        $this->assertStringContainsString('<input type="time" data-name="h">', $limpio);
        $this->assertStringContainsString('<input type="time" data-name="h2" value="08:05">', $limpio);
        $this->assertStringContainsString('<input type="range" data-name="r" max="10" step="0.5" value="2">', $limpio);
        $this->assertStringContainsString('<input type="checkbox" data-name="c" data-off="nel" checked>', $limpio);
        $this->assertStringContainsString('<input data-name="t" placeholder="Texto" value="pre">', $limpio);
        $this->assertSame($limpio, Validador::sanear($limpio));
    }

    public function test_select_normaliza_opciones_y_elegidas(): void
    {
        $texto = $this->teclado('<select data-name="s" placeholder="Elige">'
            .'<option value="a" selected>A</option><option value="b" selected>B</option><option value="a">Repetida</option>'
            .'<option value="">Vacía</option><option>Sin valor</option><option value="'.str_repeat('x', 201).'">Larga</option></select>'
            .'<select data-name="m" multiple><option value="1" selected>Uno</option><option value="2" selected>Dos</option></select>'
            .'<button data-say="{s} {m}">ok</button>');
        $limpio = Validador::sanear($texto);

        $this->assertSame([], Validador::errores($texto));
        $this->assertStringContainsString('<select data-name="s" placeholder="Elige"><option value="a" selected>A</option><option value="b">B</option><option value="Sin valor">Sin valor</option></select>', $limpio);
        $this->assertStringContainsString('<select data-name="m" multiple><option value="1" selected>Uno</option><option value="2" selected>Dos</option></select>', $limpio);
    }

    public function test_select_con_mas_de_32_opciones_degrada_el_bloque(): void
    {
        $opciones = '';
        for ($i = 0; $i < 33; $i++) {
            $opciones .= '<option value="v'.$i.'">O'.$i.'</option>';
        }
        $texto = $this->teclado('<select data-name="s">'.$opciones.'</select><button data-say="{s}">ok</button>');
        $this->assertStringStartsWith('```text', Validador::sanear($texto));
        $this->assertStringContainsString('más de 32 opciones', Validador::errores($texto)[0]);

        $justo = $this->teclado('<select data-name="s">'.substr($opciones, 0, strpos($opciones, '<option value="v32"')).'</select><button data-say="{s}">ok</button>');
        $this->assertSame([], Validador::errores($justo));
    }

    public function test_nombres_de_campo_invalidos_repetidos_o_ausentes_degradan(): void
    {
        foreach ([
            '<input><button data-say="a">a</button>',
            '<input data-name="con espacio"><button data-say="a">a</button>',
            '<input data-name="'.str_repeat('a', 33).'"><button data-say="a">a</button>',
            '<input data-name="x"><input data-name="x"><button data-say="a">a</button>',
            '<select data-name=""><option>a</option></select><button data-say="a">a</button>',
            '<input data-name="ñ"><button data-say="a">a</button>',
        ] as $interior) {
            $texto = $this->teclado($interior);
            $this->assertStringStartsWith('```text', Validador::sanear($texto), $interior);
            $this->assertCount(1, Validador::errores($texto), $interior);
        }
    }

    public function test_mas_de_8_campos_y_mas_de_64_teclas_degradan(): void
    {
        $nueve = '';
        for ($i = 0; $i < 9; $i++) {
            $nueve .= '<input data-name="c'.$i.'">';
        }
        $this->assertStringStartsWith('```text', Validador::sanear($this->teclado($nueve.'<button data-say="a">a</button>')));
        $this->assertSame([], Validador::errores($this->teclado(substr($nueve, 0, strlen($nueve) - strlen('<input data-name="c8">')).'<button data-say="a">a</button>')));

        $teclas = fn (int $n): string => implode('', array_map(fn (int $i): string => '<button data-say="d'.$i.'">'.$i.'</button>', range(1, $n)));
        $this->assertSame([], Validador::errores($this->teclado($teclas(64))));
        $this->assertCount(1, Validador::errores($this->teclado($teclas(65))));
    }

    public function test_data_say_de_mas_de_500_degrada_y_con_500_pasa(): void
    {
        $this->assertSame([], Validador::errores($this->teclado('<button data-say="'.str_repeat('a', 500).'">a</button>')));
        $texto = $this->teclado('<button data-say="'.str_repeat('a', 501).'">a</button>');
        $this->assertStringStartsWith('```text', Validador::sanear($texto));
        $this->assertStringContainsString('501', Validador::errores($texto)[0]);
    }

    public function test_un_marcador_sin_campo_degrada_pero_una_llave_normal_no(): void
    {
        $this->assertCount(1, Validador::errores($this->teclado('<button data-say="mando {importe}">a</button>')));
        $this->assertSame([], Validador::errores($this->teclado('<button data-say="mando {con espacio} y {}">a</button>')));
        $this->assertSame([], Validador::errores($this->teclado('<input data-name="importe"><button data-say="mando {importe}">a</button>')));
    }

    public function test_solo_dos_bloques_keyboard_por_mensaje(): void
    {
        $uno = $this->teclado('<button data-say="a">a</button>');
        $texto = "{$uno}\n\n{$uno}\n\n{$uno}";
        $limpio = Validador::sanear($texto);

        $this->assertSame(2, substr_count($limpio, '```keyboard'));
        $this->assertSame(1, substr_count($limpio, '```text'));
        $this->assertStringContainsString('más de 2 bloques keyboard', Validador::errores($texto)[0]);
        $this->assertTrue(Validador::esValido("{$uno}\n\n{$uno}"));
    }

    public function test_un_html_no_cuenta_para_el_limite_de_teclados(): void
    {
        $k = $this->teclado('<button data-say="a">a</button>');
        $h = $this->html('<p>x</p>');
        $this->assertSame([], Validador::errores("{$k}\n{$h}\n{$k}\n{$h}"));
    }

    public function test_el_texto_no_es_valido_si_hay_que_cambiarlo(): void
    {
        $this->assertTrue(Validador::esValido('hola'));
        $this->assertFalse(Validador::esValido($this->html('<script>x</script><p>a</p>')));
        $this->assertFalse(Validador::esValido($this->html('<p>a</p><p>b</p>')), 'la forma canonica pone cada bloque en su linea');
    }

    public function test_bloque_devuelve_el_contenido_canonico_o_lanza(): void
    {
        $this->assertSame('<p>a</p>', Validador::bloque('html', '<P onclick="x">a</P>'));
        $this->assertSame('<button data-say="x"></button>', Validador::bloque('keyboard', '<button data-say="x"></button>'));

        $this->expectException(MarcadoInvalido::class);
        Validador::bloque('keyboard', '<p>sin teclas</p>');
    }

    public function test_entrada_rota_no_lanza_y_siempre_da_algo_valido(): void
    {
        $basura = [
            '<<<>>><p<p>', "<p>\x00\x01 control</p>", "\xff\xfe<p>utf8 roto</p>", '<p>'.str_repeat('<b>', 100).'x', '<table><tr><td>a<td>b<tr><td>c</table>',
            '<button data-say=\'a"b\'>x</button>', '<input data-name="a" value="'.str_repeat('é', 300).'">', '<p style="'.str_repeat('a:b;', 500).'">x</p>',
            '&#0;&#xD800;&#99999999;&bogus;', '<p title="`````">x</p>', '<!--[if IE]><p>x</p><![endif]--><p>y</p>', '<![CDATA[x]]><p>y</p>',
        ];
        foreach ($basura as $entrada) {
            foreach (['html', 'keyboard'] as $tipo) {
                $salida = Validador::sanear("```{$tipo}\n{$entrada}\n```");
                $this->assertTrue(mb_check_encoding($salida, 'UTF-8'));
                $this->assertSame($salida, Validador::sanear($salida), 'idempotente: '.$entrada);
                $this->assertStringNotContainsString('<script', $salida);
            }
        }
    }

    public function test_los_saltos_y_vallas_en_atributos_y_etiquetas_no_sacan_una_linea_de_valla(): void
    {
        $limpio = Validador::sanear($this->teclado("<button data-say=\"uno\n```dos\">tecla\n``` x</button>"));
        $this->assertSame([], Validador::errores($limpio));
        $this->assertStringContainsString('data-say="uno &#96;&#96;&#96;dos">tecla &#96;&#96;&#96; x</button>', $limpio);
        $this->assertCount(3, explode("\n", $limpio), 'solo las dos vallas y el contenido, en una linea');
        $this->assertSame($limpio, Validador::sanear($limpio));
    }

    public function test_pre_conserva_los_saltos_de_linea(): void
    {
        $limpio = Validador::sanear($this->html("<pre>a\n  b</pre>"));
        $this->assertSame($this->html("<pre>a\n  b</pre>"), $limpio);
    }

    public function test_profundidad_maxima_desenvuelve_sin_perder_el_texto(): void
    {
        $profundo = str_repeat('<div>', 40).'fondo'.str_repeat('</div>', 40);
        $limpio = Validador::sanear($this->html($profundo));
        $this->assertStringContainsString('fondo', $limpio);
        $this->assertLessThanOrEqual(10, substr_count($limpio, '<div>'));
    }
}
