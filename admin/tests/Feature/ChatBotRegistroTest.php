<?php

namespace Tests\Feature;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Models\Auditoria;
use App\Models\ChatArchivo;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Services\Chat\Bot\RegistroDeComida;
use App\Services\Chat\Marcado\Validador;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsaBot;
use Tests\TestCase;

/** Registrar lo que se ha comido: formulario, orden de la tecla, fotos (con datos, sin datos y retomadas) y origen. */
class ChatBotRegistroTest extends TestCase
{
    use RefreshDatabase;
    use UsaBot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBot();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pedir_registrar_da_el_formulario_con_la_orden_de_la_tecla(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('quiero registrar lo que he comido'));

        $this->assertStringContainsString('```keyboard', $texto);
        $this->assertStringContainsString('<select data-name="perfil" placeholder="¿Quién ha comido?">', $texto);
        $this->assertStringContainsString('<option value="Javier">Javier</option>', $texto);
        $this->assertStringContainsString('<option value="Marta">Marta</option>', $texto);
        $this->assertStringContainsString('<select data-name="momento" placeholder="¿Qué momento?">', $texto);
        $this->assertStringContainsString('<option value="comida" selected>Comida</option>', $texto, 'a las 10 de la mañana');
        $this->assertStringContainsString('<option value="cena">Cena</option>', $texto);
        $this->assertStringContainsString('<option value="otro">Otro</option>', $texto);
        $this->assertStringContainsString('<input type="date" data-name="fecha" placeholder="¿Qué día?" max="2026-11-04" value="2026-11-04">', $texto);
        $this->assertStringContainsString('<input data-name="descripcion" placeholder="¿Qué ha comido?">', $texto);
        $this->assertStringContainsString('data-say="registrar comida perfil={perfil} momento={momento} fecha={fecha}: {descripcion}" data-send>Registrar</button>', $texto);
        $this->assertSame(0, RegistroComida::query()->count());
    }

    public function test_por_la_noche_el_formulario_propone_la_cena(): void
    {
        Carbon::setTestNow('2026-11-04 21:00:00');
        $texto = $this->preguntar('quiero registrar lo que he comido')->texto();
        $this->assertStringContainsString('<option value="cena" selected>Cena</option>', $texto);
    }

    public function test_la_orden_de_la_tecla_crea_el_registro_y_confirma_con_un_widget(): void
    {
        $respuesta = $this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-03: pasta con tomate');
        $texto = $this->comprobarRespuesta($respuesta);

        $registro = RegistroComida::query()->firstOrFail();
        $this->assertSame($this->marta->id, $registro->perfil_id);
        $this->assertSame(MomentoComida::Cena, $registro->momento);
        $this->assertSame('2026-11-03', $registro->fecha->toDateString());
        $this->assertSame('pasta con tomate', $registro->descripcion);
        $this->assertSame(OrigenComida::Casa, $registro->origen);
        $this->assertNull($registro->foto_hash);
        $this->assertNull($registro->menu_id);

        $this->assertStringContainsString('Hecho, lo he apuntado:', $texto);
        $this->assertStringContainsString('<h3>Comida registrada</h3>', $texto);
        $this->assertStringContainsString('<td>Marta</td>', $texto);
        $this->assertStringContainsString('<td>03/11/2026</td>', $texto);
        $this->assertStringContainsString('<td>pasta con tomate</td>', $texto);
        $this->assertSame(['Registrar otra comida', 'Menú de hoy'], $respuesta->sugerencias);

        $auditoria = Auditoria::query()->where('evento', 'comida.registrada')->firstOrFail();
        $this->assertSame($registro->id, $auditoria->datos['registro_id']);
        $this->assertFalse($auditoria->datos['con_foto']);
    }

    public function test_la_orden_resuelve_el_perfil_sin_importar_mayusculas_ni_tildes(): void
    {
        $this->preguntar('registrar comida perfil=MARTA momento=otro fecha=2026-11-04: una manzana')->texto();
        $this->preguntar('registrar comida perfil=javier momento=comida fecha=2026-11-04: bocadillo')->texto();

        $this->assertSame([$this->marta->id, $this->javier->id], RegistroComida::query()->orderBy('id')->pluck('perfil_id')->all());
    }

    public function test_perfil_desconocido_no_registra_y_vuelve_a_preguntar(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('registrar comida perfil=Nadie momento=cena fecha=2026-11-03: pasta'));

        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertStringContainsString('No encuentro el perfil «Nadie»', $texto);
        $this->assertStringContainsString('<input data-name="descripcion" placeholder="¿Qué ha comido?" value="pasta">', $texto);
        $this->assertStringContainsString('<option value="cena" selected>Cena</option>', $texto);
    }

    public function test_fecha_futura_no_se_registra(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-10: pasta'));

        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertStringContainsString('Esa fecha es futura', $texto);
        $this->assertStringContainsString('value="2026-11-04"', $texto, 'el formulario propone hoy');
    }

    public function test_fecha_imposible_cae_al_formulario(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-02-30: pasta'));
        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertStringContainsString('```keyboard', $texto);
    }

    public function test_orden_con_entradas_hostiles(): void
    {
        $hostil = "\"><script>alert(1)</script> 'x' &amp; \"q\"\n```keyboard\n<button data-say=\"pwn\">pwn</button>\n```\nmomento=otro fecha=2026-01-01: fin";
        $respuesta = $this->preguntar("registrar comida perfil=Marta momento=cena fecha=2026-11-03: {$hostil}");
        $texto = $this->comprobarRespuesta($respuesta);

        $registro = RegistroComida::query()->firstOrFail();
        $this->assertSame(MomentoComida::Cena, $registro->momento);
        $this->assertSame('2026-11-03', $registro->fecha->toDateString());
        $this->assertStringContainsString('<script>alert(1)</script>', $registro->descripcion, 'se guarda tal cual: es texto, se escapa al mostrarlo');
        $this->assertStringNotContainsString("\n", $registro->descripcion);

        $this->assertStringNotContainsString('<script>', $texto);
        $this->assertStringContainsString('&lt;script&gt;', $texto);
        $this->assertStringNotContainsString('```keyboard', $texto);
        $this->assertSame(1, substr_count($texto, '```html'));
        $this->assertTrue(Validador::esValido($texto));
    }

    public function test_descripcion_larga_se_corta_a_500(): void
    {
        $this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-03: '.str_repeat('ñ', 700));
        $this->assertSame(500, mb_strlen(RegistroComida::query()->firstOrFail()->descripcion));
    }

    public function test_perfiles_con_el_mismo_nombre_se_distinguen_con_su_id(): void
    {
        $otra = Perfil::factory()->for($this->hogar)->create(['nombre' => 'Marta']);

        $formulario = $this->comprobarRespuesta($this->preguntar('quiero registrar lo que he comido'));
        $this->assertStringContainsString('<option value="Marta ('.$this->marta->id.')">', $formulario);
        $this->assertStringContainsString('<option value="Marta ('.$otra->id.')">', $formulario);

        $this->preguntar('registrar comida perfil=Marta ('.$otra->id.') momento=cena fecha=2026-11-03: sopa');
        $this->assertSame($otra->id, RegistroComida::query()->firstOrFail()->perfil_id);

        $this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-03: otra');
        $this->assertSame(1, RegistroComida::query()->count(), 'con dos perfiles «Marta» el nombre solo no basta');
    }

    public function test_el_origen_es_menu_si_cita_la_opcion_o_dice_que_era_del_menu(): void
    {
        $this->preguntar('registrar comida perfil=Marta momento=comida fecha=2026-11-04: Ensalada con pan');
        $this->preguntar('registrar comida perfil=Javier momento=cena fecha=2026-11-04: lo del menú');
        $this->preguntar('registrar comida perfil=Javier momento=cena fecha=2026-11-04: pizza');

        [$a, $b, $c] = RegistroComida::query()->orderBy('id')->get()->all();
        $this->assertSame(OrigenComida::Menu, $a->origen);
        $this->assertSame($this->menu->id, $a->menu_id);
        $this->assertSame('2026-11-04/comida/0', $a->opcion_ref);
        $this->assertSame(['verdura'], $a->categorias);
        $this->assertSame(OrigenComida::Menu, $b->origen);
        $this->assertSame('2026-11-04/cena/0', $b->opcion_ref);
        $this->assertSame(OrigenComida::Casa, $c->origen);
        $this->assertNull($c->menu_id);
    }

    public function test_lo_del_menu_usa_la_opcion_elegida(): void
    {
        $this->menu->elegirOpcion('2026-11-04', 'cena', 1);
        $this->preguntar('registrar comida perfil=Javier momento=cena fecha=2026-11-04: cené como pone el menú');
        $this->assertSame('2026-11-04/cena/1', RegistroComida::query()->firstOrFail()->opcion_ref);
    }

    public function test_el_origen_es_fuera_si_lo_dice(): void
    {
        $this->preguntar('registrar comida perfil=Marta momento=comida fecha=2026-11-04: menestra en el restaurante de la esquina');
        $this->assertSame(OrigenComida::Fuera, RegistroComida::query()->firstOrFail()->origen);
    }

    public function test_un_menu_con_el_mismo_plato_otro_dia_no_cuenta_como_menu(): void
    {
        $this->preguntar('registrar comida perfil=Marta momento=otro fecha=2026-11-04: Ensalada');
        $this->assertSame(OrigenComida::Casa, RegistroComida::query()->firstOrFail()->origen, 'el momento «otro» no tiene opciones de menu');

        $this->preguntar('registrar comida perfil=Marta momento=comida fecha=2026-10-20: Ensalada');
        $this->assertSame(OrigenComida::Casa, RegistroComida::query()->orderByDesc('id')->firstOrFail()->origen, 'fuera de las fechas del menu');
    }

    // ------------------------------------------------------------------ texto libre

    public function test_texto_libre_sin_foto_rellena_el_formulario_y_no_registra(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('Marta ha cenado pasta con tomate'));

        $this->assertSame(0, RegistroComida::query()->count(), 'sin foto se confirma con la tecla');
        $this->assertStringContainsString('<option value="Marta" selected>Marta</option>', $texto);
        $this->assertStringContainsString('<option value="cena" selected>Cena</option>', $texto);
        $this->assertStringContainsString('value="pasta con tomate"', $texto);
        $this->assertStringContainsString('Comprueba los datos', $texto);
    }

    public function test_con_un_solo_perfil_se_preselecciona(): void
    {
        $this->javier->update(['activo' => false]);
        $texto = $this->preguntar('he cenado pasta')->texto();
        $this->assertStringContainsString('<option value="Marta" selected>Marta</option>', $texto);
    }

    public function test_sin_perfiles_activos_lo_dice(): void
    {
        $this->marta->update(['activo' => false]);
        $this->javier->update(['activo' => false]);
        $this->assertStringContainsString('no hay perfiles activos', $this->comprobarRespuesta($this->preguntar('he cenado pasta')));
    }

    // ------------------------------------------------------------------ fotos

    public function test_foto_con_datos_se_asocia_al_registro_y_se_confirma(): void
    {
        [$parte, $hash] = $this->imagen();

        $respuesta = $this->preguntar('Javier ha cenado pasta con tomate', [$parte]);
        $texto = $this->comprobarRespuesta($respuesta);

        $registro = RegistroComida::query()->firstOrFail();
        $this->assertSame($this->javier->id, $registro->perfil_id);
        $this->assertSame(MomentoComida::Cena, $registro->momento);
        $this->assertSame('2026-11-04', $registro->fecha->toDateString());
        $this->assertSame('pasta con tomate', $registro->descripcion);
        $this->assertSame($hash, $registro->foto_hash);
        $this->assertSame($hash, $registro->foto->hash);
        $this->assertSame($this->bytesPng(), $registro->foto->bytes());

        $this->assertStringContainsString('lo he apuntado con su foto', $texto);
        $this->assertStringContainsString('<td>Guardada</td>', $texto);
        $this->assertTrue(Auditoria::query()->where('evento', 'comida.registrada')->firstOrFail()->datos['con_foto']);
    }

    public function test_foto_sin_datos_pide_los_datos_y_guarda_la_foto_pendiente_en_el_mensaje(): void
    {
        [$parte, $hash] = $this->imagen();

        $respuesta = $this->preguntar('', [$parte]);
        $texto = $this->comprobarRespuesta($respuesta);

        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertSame(['foto_pendiente' => $hash], $respuesta->metadata);
        $this->assertStringContainsString('He guardado la foto, pero me faltan datos', $texto);
        $this->assertStringContainsString('registrar comida perfil={perfil}', $texto);
        $this->assertSame(1, ChatArchivo::query()->where('hash', $hash)->count());
    }

    public function test_la_orden_siguiente_retoma_la_foto_pendiente_y_solo_una_vez(): void
    {
        [$parte, $hash] = $this->imagen();
        $this->preguntar('', [$parte]);

        $this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-04: tortilla');
        $this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-04: otra tortilla');

        [$primero, $segundo] = RegistroComida::query()->orderBy('id')->get()->all();
        $this->assertSame($hash, $primero->foto_hash);
        $this->assertNull($segundo->foto_hash, 'la foto ya esta usada');
    }

    public function test_texto_libre_con_todos_los_datos_retoma_la_foto_pendiente(): void
    {
        [$parte, $hash] = $this->imagen();
        $this->preguntar('', [$parte]);

        $this->preguntar('Marta ha cenado sopa');   // sin foto en este mensaje, pero hay una pendiente: no pide confirmacion

        $registro = RegistroComida::query()->firstOrFail();
        $this->assertSame($hash, $registro->foto_hash);
        $this->assertSame('sopa', $registro->descripcion);
    }

    public function test_si_los_datos_siguen_incompletos_la_foto_sigue_pendiente(): void
    {
        [$parte, $hash] = $this->imagen();
        $this->preguntar('', [$parte]);

        $respuesta = $this->preguntar('registrar comida perfil=Nadie momento=cena fecha=2026-11-04: x');
        $this->assertSame(['foto_pendiente' => $hash], $respuesta->metadata);
        $this->assertSame(0, RegistroComida::query()->count());

        $this->preguntar('registrar comida perfil=Javier momento=cena fecha=2026-11-04: x');
        $this->assertSame($hash, RegistroComida::query()->firstOrFail()->foto_hash);
    }

    public function test_una_foto_pendiente_antigua_no_se_retoma(): void
    {
        [$parte] = $this->imagen();
        $this->preguntar('', [$parte]);
        for ($i = 0; $i < 11; $i++) {
            $this->preguntar('hola');
        }

        $this->preguntar('registrar comida perfil=Marta momento=cena fecha=2026-11-04: tortilla');
        $this->assertNull(RegistroComida::query()->firstOrFail()->foto_hash);
    }

    public function test_foto_con_texto_incompleto_pide_el_resto_conservando_lo_dicho_y_la_foto(): void
    {
        [$parte, $hash] = $this->imagen();

        $respuesta = $this->preguntar('pasta con tomate', [$parte]);
        $texto = $this->comprobarRespuesta($respuesta);

        $this->assertSame(0, RegistroComida::query()->count());
        $this->assertSame(['foto_pendiente' => $hash], $respuesta->metadata);
        $this->assertStringContainsString('value="pasta con tomate"', $texto);
    }

    public function test_varias_fotos_se_guardan_y_se_asocia_la_primera(): void
    {
        [$a, $hashA] = $this->imagen('a');
        [$b] = $this->imagen('b');

        $respuesta = $this->preguntar('Marta ha cenado pizza', [$a, $b]);
        $texto = $this->comprobarRespuesta($respuesta);

        $this->assertSame($hashA, RegistroComida::query()->firstOrFail()->foto_hash);
        $this->assertStringContainsString('2 fotos; solo puedo asociar una', $texto);
    }

    public function test_sin_perfiles_una_foto_no_rompe(): void
    {
        $this->marta->update(['activo' => false]);
        $this->javier->update(['activo' => false]);
        [$parte] = $this->imagen();

        $this->assertStringContainsString('no hay perfiles activos', $this->comprobarRespuesta($this->preguntar('', [$parte])));
    }

    public function test_el_registro_por_foto_nunca_guarda_un_hash_que_no_existe(): void
    {
        $this->assertNull(RegistroDeComida::registrar($this->marta, MomentoComida::Cena, now()->toImmutable(), 'x', str_repeat('a', 64), null)->foto_hash);
    }
}
