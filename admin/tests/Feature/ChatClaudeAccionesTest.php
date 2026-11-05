<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Feedback;
use App\Models\RegistroComida;
use App\Services\Chat\Claude\AccionesChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PreparaChatClaude;
use Tests\TestCase;

class ChatClaudeAccionesTest extends TestCase
{
    use PreparaChatClaude, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararClaude();
    }

    protected function tearDown(): void
    {
        $this->limpiarClaude();
        parent::tearDown();
    }

    /** @param  array<int, mixed>  $acciones  @return array<int, array{tipo: string, estado: string, detalle: string}> */
    private function procesar(array $acciones): array
    {
        return app(AccionesChat::class)->procesar((string) json_encode(['acciones' => $acciones], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function comida(array $cambios = []): array
    {
        return $cambios + ['tipo' => 'registrar_comida', 'perfil' => $this->marta->id, 'fecha' => '2026-11-05', 'momento' => 'comida', 'descripcion' => 'Lentejas con arroz'];
    }

    private function columnaFoto(): void
    {
        Schema::hasColumn('registros_comida', 'foto_hash') || Schema::table('registros_comida', fn ($t) => $t->string('foto_hash', 64)->nullable());
    }

    public function test_registrar_comida_valida_se_aplica_y_se_audita(): void
    {
        $r = $this->procesar([$this->comida(['categorias' => ['legumbre', 'cereal'], 'origen' => 'fuera'])]);

        $this->assertSame('aplicada', $r[0]['estado']);
        $this->assertStringContainsString('Marta', $r[0]['detalle']);
        $registro = RegistroComida::query()->firstOrFail();
        $this->assertSame($this->marta->id, $registro->perfil_id);
        $this->assertSame('2026-11-05', $registro->fecha->toDateString());
        $this->assertSame('comida', $registro->momento->value);
        $this->assertSame('fuera', $registro->origen->value);
        $this->assertSame(['legumbre', 'cereal'], $registro->categorias);
        $this->assertSame('Lentejas con arroz', $registro->descripcion);
        $auditoria = Auditoria::query()->where('evento', 'chat.accion_aplicada')->firstOrFail();
        $this->assertSame('registrar_comida', $auditoria->datos['tipo']);
    }

    public function test_el_perfil_se_puede_dar_por_nombre_si_es_unico(): void
    {
        $this->assertSame('aplicada', $this->procesar([$this->comida(['perfil' => 'javier'])])[0]['estado']);
        $this->assertSame($this->javier->id, RegistroComida::query()->firstOrFail()->perfil_id);
    }

    public function test_la_foto_se_enlaza_si_es_de_la_conversacion(): void
    {
        $this->columnaFoto();
        $parte = $this->foto($this->bytesPng());
        $this->preguntar('comí esto', [$parte]);

        $r = $this->procesar([$this->comida(['foto_hash' => $parte['hash']])]);

        $this->assertSame('aplicada', $r[0]['estado']);
        $this->assertSame($parte['hash'], RegistroComida::query()->firstOrFail()->foto_hash);
        $this->assertStringContainsString('con la foto', $r[0]['detalle']);
    }

    public function test_una_foto_que_no_es_de_la_conversacion_se_rechaza(): void
    {
        $this->columnaFoto();
        $this->subir($this->bytesPng())->assertOk();   // guardada, pero ningun mensaje de la persona la envio
        $ajena = hash('sha3-256', $this->bytesPng());

        $this->assertSame('rechazada', $this->procesar([$this->comida(['foto_hash' => $ajena])])[0]['estado']);
        $this->assertSame('rechazada', $this->procesar([$this->comida(['foto_hash' => str_repeat('a', 64)])])[0]['estado']);
        $this->assertSame('rechazada', $this->procesar([$this->comida(['foto_hash' => 'no-es-un-hash'])])[0]['estado']);
        $this->assertSame(0, RegistroComida::query()->count());
    }

    public function test_la_foto_de_un_mensaje_del_servicio_no_vale(): void
    {
        $this->columnaFoto();
        $this->subir($this->bytesPng())->assertOk();
        [, $respuesta] = $this->preguntar('hola');
        $respuesta->anadirImagen(hash('sha3-256', $this->bytesPng()));

        $this->assertSame('rechazada', $this->procesar([$this->comida(['foto_hash' => hash('sha3-256', $this->bytesPng())])])[0]['estado']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function rechazos(): array
    {
        return [
            'perfil inexistente' => [['perfil' => 99999], 'perfil'],
            'perfil por nombre desconocido' => [['perfil' => 'Nadie'], 'Nadie'],
            'fecha futura' => [['fecha' => '2026-11-06'], 'futura'],
            'fecha muy antigua' => [['fecha' => '2026-01-01'], 'antigua'],
            'fecha imposible' => [['fecha' => '2026-02-31'], 'no existe'],
            'fecha mal escrita' => [['fecha' => '5/11/2026'], 'AAAA-MM-DD'],
            'momento desconocido' => [['momento' => 'desayuno'], 'momento'],
            'origen menu' => [['origen' => 'menu'], 'origen'],
            'descripcion corta' => [['descripcion' => 'ab'], 'descripcion'],
            'descripcion larga' => [['descripcion' => 'x'], 'descripcion'],   // se rellena en la prueba
            'descripcion con control' => [['descripcion' => "hola\x00mundo"], 'descripcion'],
            'categoria invalida' => [['categorias' => ['marciano']], 'categorias'],
            'categorias no lista' => [['categorias' => 'verdura'], 'categorias'],
            'campo no admitido' => [['precio' => 3], 'precio'],
        ];
    }

    /** @param  array<string, mixed>  $cambio */
    #[DataProvider('rechazos')]
    public function test_una_accion_invalida_no_se_aplica_y_se_explica(array $cambio, string $motivo): void
    {
        if (($cambio['descripcion'] ?? null) === 'x') {
            $cambio['descripcion'] = str_repeat('x', 501);
        }

        $r = $this->procesar([$this->comida($cambio)]);

        $this->assertSame('rechazada', $r[0]['estado']);
        $this->assertStringContainsString($motivo, $r[0]['detalle']);
        $this->assertSame(0, RegistroComida::query()->count());
        $auditoria = Auditoria::query()->where('evento', 'chat.accion_rechazada')->firstOrFail();
        $this->assertSame($r[0]['detalle'], $auditoria->datos['motivo']);
        $this->assertSame(0, Auditoria::query()->where('evento', 'chat.accion_aplicada')->count());
    }

    public function test_un_perfil_inactivo_o_un_nombre_repetido_se_rechazan(): void
    {
        $this->javier->update(['activo' => false]);
        $this->assertStringContainsString('perfil activo', $this->procesar([$this->comida(['perfil' => $this->javier->id])])[0]['detalle']);
        $this->marta->replicate()->forceFill(['hogar_id' => $this->hogar->id, 'nombre' => 'Marta'])->save();

        $this->assertStringContainsString('varios perfiles', $this->procesar([$this->comida(['perfil' => 'Marta'])])[0]['detalle']);
    }

    public function test_tipo_desconocido_o_acciones_que_no_son_objetos(): void
    {
        $r = $this->procesar([['tipo' => 'borrar_todo'], 'texto', ['sin' => 'tipo'], ['tipo' => 'registrar_comida', 'perfil' => $this->marta->id, 'sql' => 'drop table users']]);

        $this->assertSame(['rechazada', 'rechazada', 'rechazada', 'rechazada'], array_column($r, 'estado'));
        $this->assertStringContainsString('desconocido', $r[0]['detalle']);
        $this->assertStringContainsString('no admitidos', $r[3]['detalle']);
    }

    public function test_un_archivo_que_no_es_json_o_no_tiene_el_formato_se_rechaza_entero(): void
    {
        foreach (['no es json', '{"acciones": "x"}', '[]', '{"acciones": {"a": 1}}', str_repeat(' ', 70000)] as $malo) {
            $r = app(AccionesChat::class)->procesar($malo);
            $this->assertSame('rechazada', $r[0]['estado'], substr($malo, 0, 20));
        }
        $this->assertSame(5, Auditoria::query()->where('evento', 'chat.accion_rechazada')->count());
    }

    public function test_mezcla_de_validas_e_invalidas_aplica_solo_las_validas(): void
    {
        $r = $this->procesar([$this->comida(), $this->comida(['perfil' => 99999]), $this->comida(['perfil' => $this->javier->id, 'momento' => 'cena', 'descripcion' => 'Tortilla'])]);

        $this->assertSame(['aplicada', 'rechazada', 'aplicada'], array_column($r, 'estado'));
        $this->assertSame(2, RegistroComida::query()->count());
        $this->assertSame(2, Auditoria::query()->where('evento', 'chat.accion_aplicada')->count());
        $this->assertSame(1, Auditoria::query()->where('evento', 'chat.accion_rechazada')->count());
    }

    public function test_mas_de_diez_acciones_en_un_turno_rechaza_las_sobrantes(): void
    {
        $r = $this->procesar(array_fill(0, 12, $this->comida()));

        $this->assertSame(10, RegistroComida::query()->count());
        $this->assertSame(['rechazada', 'rechazada'], array_column(array_slice($r, 10), 'estado'));
    }

    public function test_si_falla_al_guardar_no_se_aplica_ninguna(): void
    {
        $n = 0;
        RegistroComida::creating(function () use (&$n): void {
            if (++$n === 2) {
                throw new \RuntimeException('fallo simulado');
            }
        });

        $r = $this->procesar([$this->comida(), $this->comida(['momento' => 'cena'])]);

        $this->assertSame(0, RegistroComida::query()->count(), 'la transacción se deshace entera');
        $this->assertSame(['rechazada', 'rechazada'], array_column($r, 'estado'));
        $this->assertStringContainsString('ningún cambio', $r[0]['detalle']);
        $this->assertSame(0, Auditoria::query()->where('evento', 'chat.accion_aplicada')->count());
    }

    public function test_corregir_comida(): void
    {
        $registro = RegistroComida::factory()->create(['perfil_id' => $this->marta->id, 'fecha' => '2026-11-04', 'descripcion' => 'Lentejas']);

        $r = $this->procesar([['tipo' => 'corregir_comida', 'registro_id' => $registro->id, 'descripcion' => 'Lentejas con chorizo', 'momento' => 'cena']]);

        $this->assertSame('aplicada', $r[0]['estado']);
        $fresco = $registro->fresh();
        $this->assertSame('Lentejas con chorizo', $fresco->descripcion);
        $this->assertSame('cena', $fresco->momento->value);
        $this->assertSame('2026-11-04', $fresco->fecha->toDateString());
    }

    public function test_corregir_comida_rechaza_registros_inexistentes_antiguos_o_sin_cambios(): void
    {
        $viejo = RegistroComida::factory()->create(['perfil_id' => $this->marta->id, 'fecha' => '2026-01-02']);
        $nuevo = RegistroComida::factory()->create(['perfil_id' => $this->marta->id, 'fecha' => '2026-11-04']);

        $r = $this->procesar([
            ['tipo' => 'corregir_comida', 'registro_id' => 99999, 'descripcion' => 'algo'],
            ['tipo' => 'corregir_comida', 'registro_id' => $viejo->id, 'descripcion' => 'algo'],
            ['tipo' => 'corregir_comida', 'registro_id' => $nuevo->id],
            ['tipo' => 'corregir_comida', 'registro_id' => 'abc', 'descripcion' => 'algo'],
            ['tipo' => 'corregir_comida', 'registro_id' => $nuevo->id, 'fecha' => '2027-01-01'],
        ]);

        $this->assertSame(array_fill(0, 5, 'rechazada'), array_column($r, 'estado'));
        $this->assertNotSame('algo', $viejo->fresh()->descripcion);
    }

    public function test_anotar_feedback(): void
    {
        $r = $this->procesar([['tipo' => 'anotar_feedback', 'perfil' => $this->javier->id, 'valoracion' => 'no_gusta', 'elemento' => 'coliflor', 'nota' => 'huele fuerte'],
            ['tipo' => 'anotar_feedback', 'perfil' => $this->javier->id, 'valoracion' => 'quizá', 'elemento' => 'coliflor']]);

        $this->assertSame(['aplicada', 'rechazada'], array_column($r, 'estado'));
        $f = Feedback::query()->firstOrFail();
        $this->assertSame($this->javier->id, $f->perfil_id);
        $this->assertSame('no_gusta', $f->tipo->value);
        $this->assertSame('coliflor', $f->elemento);
        $this->assertSame('huele fuerte', $f->nota);
    }

    public function test_el_resumen_es_un_widget_html_escapado(): void
    {
        $r = $this->procesar([$this->comida(['descripcion' => 'Sopa <script>alert(1)</script> con ``` comillas']), $this->comida(['perfil' => 99999])]);

        $resumen = AccionesChat::resumen($r);

        $this->assertStringStartsWith("\n\n```html\n<div>", $resumen);
        $this->assertStringEndsWith("</div>\n```\n", $resumen);
        $this->assertStringContainsString('Cambios guardados', $resumen);
        $this->assertStringContainsString('No se han aplicado', $resumen);
        $this->assertStringNotContainsString('<script>', $resumen);
        $this->assertSame(2, substr_count($resumen, '```'), 'un backtick del texto no puede cerrar el bloque');
        $this->assertSame('', AccionesChat::resumen([]));
    }
}
