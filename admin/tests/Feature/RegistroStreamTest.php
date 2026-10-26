<?php

namespace Tests\Feature;

use App\Services\Claude\RegistroStream;
use Carbon\Carbon;
use Tests\TestCase;

class RegistroStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 10:00:00');
    }

    private function linea(array $evento): string
    {
        return json_encode($evento, JSON_UNESCAPED_UNICODE)."\n";
    }

    public function test_sesion_iniciada_con_modelo(): void
    {
        $r = new RegistroStream;

        $this->assertSame("[10:00:00] ▶ Sesión iniciada (claude-sonnet-5-5)\n", $r->alimentar($this->linea(['type' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5'])));
    }

    public function test_otros_eventos_de_sistema_y_eventos_desconocidos_se_ignoran(): void
    {
        $r = new RegistroStream;

        $this->assertSame('', $r->alimentar($this->linea(['type' => 'system', 'subtype' => 'hook'])));
        $this->assertSame('', $r->alimentar($this->linea(['type' => 'rate_limit_event'])));
    }

    public function test_texto_y_herramientas_del_asistente(): void
    {
        $r = new RegistroStream;
        $texto = $r->alimentar($this->linea(['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'text', 'text' => '  Leo el encargo.  '],
            ['type' => 'text', 'text' => '   '],
            ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'python scripts/consumos.py fase lectura --ejecucion 7']],
            ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => 'encargos/encargo-7.json']],
            ['type' => 'tool_use', 'name' => 'Raro', 'input' => ['otra' => 'cosa']],
        ]]]));

        $this->assertSame(
            "[10:00:00] 💬 Leo el encargo.\n"
            ."[10:00:00] 🔧 Bash: python scripts/consumos.py fase lectura --ejecucion 7\n"
            ."[10:00:00] 🔧 Read: encargos/encargo-7.json\n"
            ."[10:00:00] 🔧 Raro: {\"otra\":\"cosa\"}\n",
            $texto,
        );
    }

    public function test_el_resumen_de_una_herramienta_se_acorta_a_200_caracteres(): void
    {
        $r = new RegistroStream;
        $texto = $r->alimentar($this->linea(['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => str_repeat('a', 500)]],
        ]]]));

        $this->assertStringContainsString(str_repeat('a', 200).'…', $texto);
        $this->assertStringNotContainsString(str_repeat('a', 201), $texto);
    }

    public function test_resultados_de_herramienta_normales_y_con_error(): void
    {
        $r = new RegistroStream;
        $texto = $r->alimentar($this->linea(['type' => 'user', 'message' => ['content' => [
            ['type' => 'tool_result', 'content' => 'hola mundo'],
            ['type' => 'tool_result', 'content' => [['type' => 'text', 'text' => 'abc'], ['type' => 'text', 'text' => 'de']]],
            ['type' => 'tool_result', 'content' => 'Permiso denegado', 'is_error' => true],
        ]]]));

        $this->assertSame("           ↳ 10 caracteres\n           ↳ 6 caracteres\n           ↳ ⚠ Permiso denegado\n", $texto);
    }

    public function test_un_mensaje_de_usuario_con_texto_plano_no_rompe(): void
    {
        $r = new RegistroStream;

        $this->assertSame('', $r->alimentar($this->linea(['type' => 'user', 'message' => ['content' => 'hola']])));
    }

    public function test_el_resultado_guarda_la_respuesta_final_y_resume_la_ejecucion(): void
    {
        $r = new RegistroStream;
        $this->assertNull($r->resultado());

        $texto = $r->alimentar($this->linea(['type' => 'result', 'result' => 'Menú entregado', 'duration_ms' => 12400, 'num_turns' => 8, 'total_cost_usd' => 0.412]));

        $this->assertSame('Menú entregado', $r->resultado());
        $this->assertSame("[10:00:00] ✔ Terminado (12 s · 8 turnos · \$0.41)\n", $texto);
    }

    public function test_un_resultado_con_error_se_marca(): void
    {
        $r = new RegistroStream;

        $this->assertSame("[10:00:00] ✘ Terminó con error\n", $r->alimentar($this->linea(['type' => 'result', 'result' => 'Falló', 'is_error' => true])));
        $this->assertSame('Falló', $r->resultado());
    }

    public function test_los_fragmentos_partidos_a_mitad_de_linea_se_recomponen(): void
    {
        $r = new RegistroStream;
        $completa = $this->linea(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hola']]]]);
        $corte = intdiv(strlen($completa), 2);

        $this->assertSame('', $r->alimentar(substr($completa, 0, $corte)));
        $this->assertSame("[10:00:00] 💬 Hola\n", $r->alimentar(substr($completa, $corte)));
    }

    public function test_varias_lineas_en_un_mismo_fragmento(): void
    {
        $r = new RegistroStream;
        $dos = $this->linea(['type' => 'system', 'subtype' => 'init'])
            .$this->linea(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hola']]]]);

        $this->assertSame("[10:00:00] ▶ Sesión iniciada\n[10:00:00] 💬 Hola\n", $r->alimentar($dos));
    }

    public function test_las_lineas_que_no_son_json_se_dejan_tal_cual(): void
    {
        $r = new RegistroStream;

        $this->assertSame("Aviso suelto\n", $r->alimentar("Aviso suelto\n\n"));
    }

    public function test_terminar_vacia_la_ultima_linea_sin_salto(): void
    {
        $r = new RegistroStream;

        $this->assertSame('', $r->alimentar('sin salto'));
        $this->assertSame("sin salto\n", $r->terminar());
        $this->assertSame('', $r->terminar());
    }
}
