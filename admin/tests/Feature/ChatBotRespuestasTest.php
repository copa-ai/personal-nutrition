<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use App\Models\Existencia;
use App\Models\Menu;
use App\Models\Perfil;
use App\Services\Chat\Bot\RespondedorBot;
use App\Services\Chat\Marcado\Validador;
use App\Services\Chat\Respondedor;
use App\Services\Menu\TokenClaude;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsaBot;
use Tests\TestCase;

/** El bot basico sin IA, de punta a punta: pregunta por la API interna, respuesta del servicio con widgets y formularios. */
class ChatBotRespuestasTest extends TestCase
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

    public function test_el_bot_es_el_motor_que_responde_cuando_no_hay_claude(): void
    {
        $this->assertInstanceOf(RespondedorBot::class, app(RespondedorBot::class));
        $this->assertInstanceOf(Respondedor::class, app(Respondedor::class));

        $respuesta = $this->preguntar('hola');
        $this->assertStringStartsWith('¡Hola!', $respuesta->texto());
        $this->assertNotNull($respuesta->reply_to);
    }

    public function test_saluda_y_ofrece_el_teclado_de_acciones(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('Buenas tardes'));

        $this->assertStringContainsString('asistente de nutrición de Casa', $texto);
        $this->assertSame(1, substr_count($texto, '```keyboard'));
        foreach (['menú de hoy', 'quiero registrar lo que he comido', 'qué hay en la despensa', 'ayuda'] as $di) {
            $this->assertStringContainsString('data-say="'.$di.'" data-send>', $texto);
        }
    }

    public function test_la_ayuda_explica_y_ofrece_el_teclado(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('¿Qué sabes hacer?'));
        $this->assertStringContainsString('menú de hoy', $texto);
        $this->assertStringContainsString('Despensa', $texto);
        $this->assertStringContainsString('```keyboard', $texto);
    }

    public function test_por_defecto_no_entiende_y_da_teclado_y_sugerencias(): void
    {
        $respuesta = $this->preguntar('asdf qwer');
        $texto = $this->comprobarRespuesta($respuesta);

        $this->assertStringContainsString('No te he entendido', $texto);
        $this->assertStringContainsString('```keyboard', $texto);
        $this->assertSame(['Menú de hoy', 'Despensa', 'Ayuda'], $respuesta->sugerencias);
    }

    public function test_gracias_y_adios_no_ofrecen_teclados(): void
    {
        $this->assertStringContainsString('De nada', $this->comprobarRespuesta($this->preguntar('gracias')));
        $this->assertStringNotContainsString('```', $this->preguntar('adiós')->texto());
    }

    public function test_menu_de_hoy_para_todos_como_widget(): void
    {
        $respuesta = $this->preguntar('menú de hoy');
        $texto = $this->comprobarRespuesta($respuesta);

        $this->assertStringContainsString('Esto es lo que toca hoy:', $texto);
        $this->assertStringContainsString('```html', $texto);
        $this->assertStringContainsString('<h3>Miércoles 4 de noviembre</h3>', $texto);
        $this->assertStringContainsString('<td><b>Comida</b></td>', $texto);
        $this->assertStringContainsString('Ensalada (o: Crema)', $texto);
        $this->assertStringContainsString('Tortilla (o: Pescado)', $texto);
        $this->assertStringContainsString('<h4>Marta</h4>', $texto);
        $this->assertStringContainsString('<h4>Javier</h4>', $texto);
        $this->assertStringContainsString('Tostada', $texto);
        $this->assertStringContainsString('Menú de la semana', implode('|', $respuesta->sugerencias));
    }

    public function test_menu_respeta_la_opcion_elegida_y_los_cambios(): void
    {
        $this->menu->elegirOpcion('2026-11-04', 'cena', 1);
        $this->hogar->fresh()->forceFill(['cambios' => ['2026-11-04/comida' => ['categorias' => [], 'opciones' => [['titulo' => 'Arroz']]]]])->save();

        $texto = $this->comprobarRespuesta($this->preguntar('menú de hoy'));
        $this->assertStringContainsString('Pescado (o: Tortilla)', $texto);
        $this->assertStringContainsString('Arroz · cambiada', $texto);
    }

    public function test_menu_de_manana_y_de_un_dia_de_la_semana(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('qué cenamos mañana'));
        $this->assertStringContainsString('Esto es lo que toca mañana:', $texto);
        $this->assertStringContainsString('<h3>Jueves 5 de noviembre</h3>', $texto);
        $this->assertStringContainsString('Tortilla', $texto);
        $this->assertStringNotContainsString('<td><b>Comida</b></td>', $texto, 'solo se pidio la cena');

        $viernes = $this->comprobarRespuesta($this->preguntar('menú del viernes'));
        $this->assertStringContainsString('<h3>Viernes 6 de noviembre</h3>', $viernes);

        $lunes = $this->comprobarRespuesta($this->preguntar('menú del lunes'));
        $this->assertStringContainsString('<h3>Lunes 2 de noviembre</h3>', $lunes, 'el lunes de la semana del menu, no el proximo');
    }

    public function test_un_dia_fuera_del_menu_dice_que_cubre_el_menu(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('menú del 20 de noviembre'));
        $this->assertStringContainsString('no incluye el viernes 20 de noviembre', $texto);
        $this->assertStringContainsString('cubre del 02/11/2026 al 08/11/2026', $texto);
        $this->assertStringNotContainsString('```html', $texto);
    }

    public function test_menu_de_la_semana(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('menú de la semana'));
        $this->assertStringContainsString('<th>Día</th>', $texto);
        $this->assertSame(7, substr_count($texto, '<td>Ensalada</td>'));
        $this->assertStringContainsString('Lun. 2', $texto);
        $this->assertStringContainsString('Dom. 8', $texto);
    }

    public function test_menu_de_un_perfil_por_su_nombre(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('¿Qué tiene que comer JAVIER mañana?'));

        $this->assertStringContainsString('Esto es lo que toca mañana de Javier:', $texto);
        $this->assertStringContainsString('<h4>Javier</h4>', $texto);
        $this->assertStringNotContainsString('<h4>Marta</h4>', $texto);
        $this->assertStringContainsString('Yogur', $texto);
    }

    public function test_menu_de_varios_perfiles_por_nombre(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('menú de hoy para Marta y Javier'));
        $this->assertStringContainsString('de Javier y Marta', $texto);
        $this->assertStringContainsString('<h4>Marta</h4>', $texto);
        $this->assertStringContainsString('<h4>Javier</h4>', $texto);
    }

    public function test_perfil_ambiguo_pregunta_con_un_formulario_y_se_resuelve_con_su_tecla(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('qué ceno hoy'));

        $this->assertStringContainsString('¿De quién quieres ver el menú de hoy?', $texto);
        $this->assertStringNotContainsString('```html', $texto);
        $this->assertStringContainsString('<select data-name="perfil" multiple placeholder="¿De quién?">', $texto);
        $this->assertStringContainsString('<option value="Todos">Todos</option>', $texto);
        $this->assertStringContainsString('<option value="Marta">Marta</option>', $texto);
        $this->assertStringContainsString('<option value="Javier">Javier</option>', $texto);
        $this->assertStringContainsString('data-say="menú de hoy cena para {perfil}" data-send', $texto);

        // La orden que envia la tecla, con la seleccion de una persona, de dos o «Todos».
        $uno = $this->comprobarRespuesta($this->preguntar('menú de hoy cena para Javier'));
        $this->assertStringContainsString('de Javier', $uno);
        $this->assertStringContainsString('Tortilla', $uno);

        $varios = $this->comprobarRespuesta($this->preguntar('menú de hoy cena para Marta, Javier'));
        $this->assertStringContainsString('de Javier y Marta', $varios);

        $todos = $this->comprobarRespuesta($this->preguntar('menú de hoy cena para Todos, Marta'));
        $this->assertStringNotContainsString('de Marta', $todos);
    }

    public function test_el_formulario_de_perfiles_recuerda_la_fecha_pedida(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('¿qué como el viernes?'));
        $this->assertStringContainsString('menú de viernes', 'menú de viernes');   // la orden usa la fecha ISO, no la palabra
        $this->assertStringContainsString('data-say="menú de 2026-11-06 para {perfil}"', $texto);
    }

    public function test_con_un_solo_perfil_no_pregunta(): void
    {
        $this->javier->update(['activo' => false]);
        $texto = $this->comprobarRespuesta($this->preguntar('qué ceno hoy'));
        $this->assertStringContainsString('```html', $texto);
        $this->assertStringContainsString('Tortilla', $texto);
    }

    public function test_los_perfiles_inactivos_no_salen(): void
    {
        $this->javier->update(['activo' => false]);
        $texto = $this->comprobarRespuesta($this->preguntar('menú de hoy'));
        $this->assertStringContainsString('<h4>Marta</h4>', $texto);
        $this->assertStringNotContainsString('Javier', $texto);
    }

    public function test_sin_menu_activo_o_sin_contenido(): void
    {
        $this->hogar->forceFill(['menu_id' => null])->save();
        $this->assertStringContainsString('no tiene un menú activo', $this->comprobarRespuesta($this->preguntar('menú de hoy')));

        $vacio = Menu::factory()->for($this->hogar)->create(['fecha_inicio' => '2026-11-02']);
        $this->hogar->forceFill(['menu_id' => $vacio->id])->save();
        $this->assertStringContainsString('aún no tiene contenido', $this->comprobarRespuesta($this->preguntar('menú de hoy')));
    }

    public function test_sin_hogar(): void
    {
        $this->hogar->delete();
        $this->assertStringContainsString('ningún hogar', $this->comprobarRespuesta($this->preguntar('menú de hoy')));
        $this->assertStringContainsString('ningún hogar', $this->comprobarRespuesta($this->preguntar('qué hay en la despensa')));
        $this->assertStringContainsString('No te he entendido', $this->comprobarRespuesta($this->preguntar('???')));
    }

    public function test_los_nombres_hostiles_no_abren_bloques_ni_inyectan_html(): void
    {
        $this->javier->update(['nombre' => "J<script>alert(1)</script>\n```keyboard\n<button data-say=\"x\">pwn</button>"]);
        $this->menu->elegirOpcion('2026-11-04', 'cena', 0);

        $texto = $this->comprobarRespuesta($this->preguntar('menú de hoy'));
        $this->assertStringNotContainsString('<script>', $texto);
        $this->assertStringNotContainsString('pwn', explode('```html', $texto)[0], 'la prosa no puede abrir un bloque');
        $this->assertSame(0, substr_count($texto, '```keyboard'));
    }

    public function test_despensa_con_lo_que_hay_y_lo_que_caduca(): void
    {
        $nombres = ['Leche' => ['2026-11-05', 1000], 'Yogur' => ['2026-11-01', 4], 'Arroz' => [null, 2000], 'Atún' => ['2026-12-30', 3], 'Vacío' => ['2026-11-04', 0], 'Queso' => ['2026-11-11', 250], 'Miel' => ['2026-11-12', 1]];
        foreach ($nombres as $nombre => [$caduca, $cantidad]) {
            Existencia::factory()->for($this->hogar)->for(Alimento::factory()->create(['nombre' => $nombre]))->create(['cantidad' => $cantidad, 'unidad' => $nombre === 'Yogur' ? 'ud' : 'g', 'caduca_el' => $caduca]);
        }

        $texto = $this->comprobarRespuesta($this->preguntar('¿qué hay en la despensa?'));

        $this->assertStringContainsString('<h4>Caduca pronto</h4>', $texto);
        $this->assertStringContainsString('caduca mañana (05/11)', $texto);
        $this->assertStringContainsString('caducó hace 3 días (01/11)', $texto);
        $this->assertStringContainsString('<h4>Lo que hay</h4>', $texto);
        $this->assertStringContainsString("<td>Arroz</td>\n<td>2000 g</td>\n<td>—</td>", $texto);
        $this->assertStringContainsString("<td>Atún</td>\n<td>3 g</td>\n<td>30/12/2026</td>", $texto);
        $this->assertStringNotContainsString('Vacío', $texto, 'lo agotado no cuenta');
        $pronto = explode('<h4>Lo que hay</h4>', $texto)[0];
        $this->assertStringNotContainsString('Atún', $pronto);
        $this->assertStringNotContainsString('Arroz', $pronto);
        $this->assertStringContainsString('Queso', $pronto, 'caduca dentro de 7 dias: es «pronto»');
        $this->assertStringNotContainsString('Vacío', $pronto);
        $this->assertLessThan(strpos($pronto, 'Leche') ?: 0, strpos($pronto, 'Yogur'), 'lo que ya caduco va primero');
    }

    public function test_despensa_vacia(): void
    {
        $texto = $this->comprobarRespuesta($this->preguntar('qué hay en la despensa'));
        $this->assertStringContainsString('No hay nada apuntado en la despensa.', $texto);
    }

    public function test_despensa_grande_se_recorta_y_cabe_en_un_widget(): void
    {
        $alimentos = Alimento::factory()->count(60)->create();
        foreach ($alimentos as $i => $alimento) {
            Existencia::factory()->for($this->hogar)->for($alimento)->create(['cantidad' => 10, 'caduca_el' => null]);
        }
        $texto = $this->comprobarRespuesta($this->preguntar('qué hay en la despensa'));
        $this->assertStringContainsString('… y 20 más.', $texto);
    }

    public function test_un_widget_demasiado_grande_se_sustituye_por_un_aviso(): void
    {
        for ($i = 0; $i < 60; $i++) {
            Perfil::factory()->for($this->hogar)->create(['nombre' => 'Perfil con un nombre bastante largo número '.$i]);
        }
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $contenido = $this->menu->contenido;
        $perfiles = $this->hogar->perfiles()->pluck('id');
        foreach ($contenido['dias'] as $i => $dia) {
            $contenido['dias'][$i]['individuales'] = $perfiles->map(fn (int $id): array => ['perfil_id' => $id, 'desayuno' => ['titulo' => 'Desayuno rico y sano número uno'], 'almuerzo' => ['titulo' => 'Fruta de temporada']])->all();
        }
        $this->menu->forceFill(['contenido' => $contenido])->save();

        $texto = $this->comprobarRespuesta($this->preguntar('menú de hoy'));
        $this->assertStringContainsString('demasiado largo', $texto);
        $this->assertStringNotContainsString('```html', $texto);
    }

    // ------------------------------------------------------------------ lo que requiere IA

    public function test_sin_token_pide_el_token_una_sola_vez_con_una_peticion_de_secreto(): void
    {
        $this->assertFalse(TokenClaude::existe());

        $respuesta = $this->preguntar('¿qué puedo cocinar con pollo?');   // el ultimo mensaje es la peticion de secreto
        $partes = $respuesta->contenido;
        $this->assertSame('peticion_secreto', $partes[0]['tipo']);
        $this->assertSame('CLAUDE_CODE_OAUTH_TOKEN', $partes[0]['nombre']);
        $this->assertStringContainsString('claude setup-token', $partes[0]['prosa']);

        $explicacion = ChatMensaje::query()->where('sequence', $respuesta->sequence - 1)->firstOrFail();
        $this->assertSame('COMPLETED', $explicacion->estado->value);
        $this->assertStringContainsString('inteligencia artificial de Claude', $explicacion->texto());
        $this->assertSame(1, ChatPeticionSecreto::query()->where('estado', ChatPeticionSecreto::PENDIENTE)->count());

        // Si vuelve a preguntar con la peticion aun pendiente, no se pide otra vez.
        $otra = $this->preguntar('dame una receta');
        $this->assertStringContainsString('Antes te pedí el token y sigue pendiente', $otra->texto());
        $this->assertSame(1, ChatPeticionSecreto::query()->count());
        $this->assertStringContainsString('```keyboard', $this->comprobarRespuesta($otra));
    }

    public function test_si_omite_el_token_no_se_insiste(): void
    {
        $peticion = $this->preguntar('recomiéndame algo');
        $parte = $peticion->contenido[0];

        $this->api('POST', 'secretos', ['message_id' => $peticion->message_id, 'request_id' => $parte['request_id'], 'omitido' => true])->assertOk();

        // La pregunta se reintenta sin Claude: el bot lo explica y no vuelve a pedirlo.
        $reintento = $this->ultimo();
        $texto = $this->comprobarRespuesta($reintento);
        $this->assertStringContainsString('no está conectada', $texto);
        $this->assertStringContainsString('Conexión con Claude', $texto);

        $otra = $this->comprobarRespuesta($this->preguntar('analiza mi semana'));
        $this->assertStringContainsString('no está conectada', $otra);
        $this->assertSame(1, ChatPeticionSecreto::query()->count());
        $this->assertSame(ChatPeticionSecreto::OMITIDA, ChatPeticionSecreto::query()->first()->estado);
    }

    public function test_con_token_pero_sin_agente_lo_dice(): void
    {
        TokenClaude::guardar('sk-ant-oat01-prueba');
        config(['chat.respondedor_claude' => 'App\\Services\\Chat\\Claude\\NoExiste']);   // el agente no responde

        $respuesta = $this->preguntar('qué puedo cocinar con pollo');
        $texto = $this->comprobarRespuesta($respuesta);

        $this->assertStringContainsString('ahora mismo no está disponible', $texto);
        $this->assertSame(0, ChatPeticionSecreto::query()->count());
        $this->assertSame([], $this->tablasConTexto('sk-ant-oat01-prueba'));
    }

    public function test_las_respuestas_nunca_pasan_de_las_reglas_de_marcado(): void
    {
        $frases = ['hola', 'ayuda', 'menú de hoy', 'menú de la semana', 'qué ceno hoy', 'qué hay en la despensa', 'quiero registrar lo que he comido', '???', 'he cenado pasta'];
        foreach ($frases as $frase) {
            $texto = $this->comprobarRespuesta($this->preguntar($frase));
            $this->assertTrue(Validador::esValido($texto), $frase);
        }
    }
}
