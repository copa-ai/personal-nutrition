<?php

namespace Tests\Feature;

use App\Models\AgenteArchivo;
use App\Models\Ajuste;
use App\Models\Alimento;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use App\Models\Existencia;
use App\Models\Feedback;
use App\Models\RegistroComida;
use App\Models\User;
use App\Services\Chat\Claude\InstantaneaDatos;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PreparaChatClaude;
use Tests\TestCase;

class ChatClaudeInstantaneaTest extends TestCase
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

    private function exportar(string $texto = '¿Qué ceno?', array $partes = []): array
    {
        [$pregunta, $respuesta] = $this->preguntar($texto, $partes);
        $leeme = app(InstantaneaDatos::class)->exportar($pregunta, $respuesta);

        return [$leeme, InstantaneaDatos::ruta($respuesta->id), $respuesta];
    }

    /** @return array<int, string> rutas relativas de todos los archivos bajo $dir */
    private function archivos(string $dir): array
    {
        $r = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $r[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($r);

        return $r;
    }

    private function datos(): void
    {
        $this->marta->update(['objetivo' => 'Perder peso', 'kcal_diarias' => 1800]);
        $pan = Alimento::factory()->create(['nombre' => 'Pan integral']);
        Existencia::factory()->create(['hogar_id' => $this->hogar->id, 'alimento_id' => $pan->id, 'cantidad' => 2, 'unidad' => 'ud']);
        RegistroComida::factory()->create(['perfil_id' => $this->javier->id, 'fecha' => '2026-11-04', 'descripcion' => 'Macarrones', 'categorias' => ['cereal']]);
        Feedback::factory()->create(['perfil_id' => $this->marta->id, 'elemento' => 'coliflor', 'tipo' => 'no_gusta']);
        $this->menu->update(['estado' => 'terminado', 'nombre' => 'Semana 45', 'contenido' => $this->contenidoMenu()]);
        $this->hogar->activar($this->menu);
    }

    /** @return array<string, mixed> */
    private function contenidoMenu(): array
    {
        $receta = fn (string $t): array => ['titulo' => $t, 'ingredientes' => [], 'duracion_previa_min' => 0, 'duracion_momento_min' => 10, 'categorias' => ['verdura']];

        return ['dias' => [['fecha' => '2026-11-05', 'comida' => ['categorias' => ['verdura'], 'opciones' => [$receta('Ensalada'), $receta('Crema')]],
            'cena' => ['categorias' => [], 'opciones' => []],
            'individuales' => [['perfil_id' => $this->marta->id, 'desayuno' => ['titulo' => 'Tostada'], 'almuerzo' => ['titulo' => 'Fruta'], 'merienda' => ['titulo' => 'Yogur']]]]],
            'explicaciones' => []];
    }

    public function test_la_instantanea_incluye_las_tablas_utiles_y_el_indice(): void
    {
        $this->datos();

        [$leeme, $dir] = $this->exportar();

        $this->assertSame(['LEEME.json', 'alimentos.json', 'consumos.json', 'conversacion.json', 'disponibilidad.json', 'existencias.json', 'feedback.json',
            'hogares.json', 'menus.json', 'menus/menu-'.$this->menu->id.'.json', 'perfiles.json', 'registros_comida.csv', 'tomas.csv'], $this->archivos($dir));
        $this->assertSame('2026-11-05', $leeme['hoy']);
        $this->assertSame($leeme, json_decode(file_get_contents($dir.'/LEEME.json'), true));
        $this->assertContains('users y contraseñas', $leeme['excluido']);
        $porArchivo = array_column($leeme['archivos'], null, 'archivo');
        $this->assertSame(2, $porArchivo['perfiles.json']['filas']);
        $this->assertArrayHasKey('restricciones', $porArchivo['perfiles.json']['columnas']);

        $perfiles = json_decode(file_get_contents($dir.'/perfiles.json'), true);
        $this->assertSame(['Marta', 'Javier'], array_column($perfiles, 'nombre'));
        $this->assertSame(['sin lácteos'], $perfiles[0]['restricciones']);
        $this->assertSame(1800, $perfiles[0]['kcal_diarias']);
        $this->assertSame($this->menu->id, json_decode(file_get_contents($dir.'/hogares.json'), true)[0]['menu_activo_id']);
        $this->assertCount(7, json_decode(file_get_contents($dir.'/disponibilidad.json'), true));
        $this->assertSame('Pan integral', json_decode(file_get_contents($dir.'/existencias.json'), true)[0]['alimento']);
        $this->assertSame('coliflor', json_decode(file_get_contents($dir.'/feedback.json'), true)[0]['elemento']);

        $registros = file_get_contents($dir.'/registros_comida.csv');
        $this->assertStringContainsString('Macarrones', $registros);
        $this->assertStringContainsString('Javier', $registros);
        $menus = json_decode(file_get_contents($dir.'/menus.json'), true);
        $this->assertTrue($menus[0]['activo']);
        $menu = json_decode(file_get_contents($dir.'/menus/menu-'.$this->menu->id.'.json'), true);
        $this->assertSame('Ensalada', $menu['contenido']['dias'][0]['comida']['opciones'][0]['titulo']);
        $tomas = file_get_contents($dir.'/tomas.csv');
        $this->assertStringContainsString('Crema', $tomas);
        $this->assertStringContainsString('Tostada', $tomas);
    }

    public function test_el_menu_activo_lleva_los_cambios_por_intercambio(): void
    {
        $this->datos();
        $this->hogar->forceFill(['cambios' => ['2026-11-05/comida' => ['categorias' => ['verdura'], 'opciones' => [['titulo' => 'Cambiado', 'categorias' => ['verdura'], 'duracion_previa_min' => 0, 'duracion_momento_min' => 5]]]]])->save();

        [, $dir] = $this->exportar();

        $this->assertStringContainsString('Cambiado', file_get_contents($dir.'/menus/menu-'.$this->menu->id.'.json'));
        $this->assertStringContainsString('Cambiado', file_get_contents($dir.'/tomas.csv'));
    }

    public function test_no_contiene_secretos_ni_tablas_excluidas(): void
    {
        $this->datos();
        TokenClaude::guardar('sk-ant-oat01-token-secreto-de-prueba');
        User::factory()->create(['name' => 'Ana Admin', 'email' => 'ana-admin@demo.test', 'password' => 'clave-ultrasecreta-123']);
        Ajuste::fijar('autologin_usuario_id', 7);
        Auditoria::registrar('prueba.evento', 'descripcion-de-auditoria-unica');
        AgenteArchivo::query()->create(['ruta' => 'workspace/otro.json', 'contenido' => base64_encode('contenido-del-agente-unico')]);
        ChatPeticionSecreto::query()->create(['request_id' => 'req-1', 'message_id' => 'm1', 'nombre' => 'CLAUDE_CODE_OAUTH_TOKEN-unico']);
        DB::table('sessions')->insert(['id' => 'sesion-unica', 'payload' => 'payload-de-sesion-unico', 'last_activity' => 1]);
        [$pregunta, $respuesta] = $this->preguntar('hola');
        config(['chat.secreto' => 'bearer-interno-unico-0123456789']);
        app(InstantaneaDatos::class)->exportar($pregunta, $respuesta);
        $dir = InstantaneaDatos::ruta($respuesta->id);

        $todo = '';
        foreach ($this->archivos($dir) as $f) {
            $todo .= file_get_contents($dir.'/'.$f)."\n";
        }
        foreach (['sk-ant-oat01', 'clave-ultrasecreta', 'ana-admin@demo.test', 'Ana Admin', 'descripcion-de-auditoria-unica', 'contenido-del-agente-unico', 'CLAUDE_CODE_OAUTH_TOKEN',
            'payload-de-sesion-unico', 'bearer-interno-unico', 'autologin', 'remember_token', 'password', 'req-1'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $todo, "la instantánea no debe contener «{$prohibido}»");
        }
        $this->assertSame([], array_filter($this->archivos($dir), fn (string $f): bool => str_contains($f, 'users') || str_contains($f, 'sessions') || str_contains($f, 'auditor')));
    }

    public function test_cada_archivo_y_el_total_estan_acotados_y_se_avisa(): void
    {
        $this->datos();
        foreach (range(1, 120) as $i) {
            RegistroComida::factory()->create(['perfil_id' => $this->marta->id, 'fecha' => '2026-10-'.sprintf('%02d', ($i % 28) + 1), 'descripcion' => "Plato número {$i} con una descripción larga para ocupar bytes"]);
        }
        config(['chat_claude.instantanea.max_bytes_archivo' => 3000, 'chat_claude.instantanea.max_registros_comida' => 400]);

        [$leeme, $dir] = $this->exportar();

        $this->assertLessThanOrEqual(3000, filesize($dir.'/registros_comida.csv'));
        $r = array_column($leeme['archivos'], null, 'archivo')['registros_comida.csv'];
        $this->assertTrue($r['truncada']);
        $this->assertGreaterThan($r['filas'], $r['filas_totales']);
        $this->assertSame(121, $r['filas_totales']);
        $this->assertStringStartsWith('id,perfil_id,perfil,fecha', file_get_contents($dir.'/registros_comida.csv'));

        // Presupuesto total: lo que queda para los siguientes archivos baja, nunca se pasa.
        config(['chat_claude.instantanea.max_bytes_datos' => 5000, 'chat_claude.instantanea.max_bytes_archivo' => 3000]);
        [, $dir2] = $this->exportar();
        $suma = 0;
        foreach ($this->archivos($dir2) as $f) {
            $suma += $f === 'LEEME.json' || $f === 'conversacion.json' ? 0 : filesize($dir2.'/'.$f);
        }
        $this->assertLessThanOrEqual(5000, $suma);
    }

    public function test_el_historial_lleva_las_fotos_como_archivos_reales_con_tope(): void
    {
        $uno = $this->bytesPng();
        $dos = $this->bytesPng()."\x00dos";
        $tres = $this->bytesPng()."\x00tres";
        [$p1] = $this->preguntar('mira esto', [$this->foto($uno)]);
        $this->mensajePorSecuencia(2)->anadirTexto('Parece un plato de pasta.');
        $this->mensajePorSecuencia(2)->finalizar();
        config(['chat_claude.instantanea.max_fotos' => 2]);
        $this->api('POST', 'mensajes', ['content' => [['tipo' => 'texto', 'texto' => 'y estas'], $this->foto($dos), $this->foto($tres)]])->assertOk();
        $pregunta = $this->mensajePorSecuencia(3);
        $respuesta = $this->mensajePorSecuencia(4);

        $leeme = app(InstantaneaDatos::class)->exportar($pregunta, $respuesta);
        $dir = InstantaneaDatos::ruta($respuesta->id);

        $conv = json_decode(file_get_contents($dir.'/conversacion.json'), true);
        $this->assertSame([1, 2, 3], array_column($conv['mensajes'], 'sequence'), 'la respuesta abierta no entra en el historial');
        $this->assertSame(['persona', 'asistente', 'persona'], array_column($conv['mensajes'], 'de'));
        $this->assertSame('Parece un plato de pasta.', $conv['mensajes'][1]['texto']);
        $this->assertTrue($conv['mensajes'][2]['es_la_pregunta']);
        $this->assertFalse($conv['mensajes'][0]['es_la_pregunta']);
        $copiadas = array_filter(array_merge(...array_column($conv['mensajes'], 'fotos')), fn (array $f): bool => $f['archivo'] !== null);
        $this->assertCount(2, $copiadas, 'tope de 2 fotos: se copian las más recientes primero');
        $this->assertSame([hash('sha3-256', $dos), hash('sha3-256', $tres)], array_column(array_values($copiadas), 'foto_hash'));
        foreach ($copiadas as $f) {
            $this->assertFileExists($dir.'/'.$f['archivo']);
        }
        $this->assertSame($dos, file_get_contents($dir.'/'.array_values($copiadas)[0]['archivo']));
        $omitida = $conv['mensajes'][0]['fotos'][0];
        $this->assertNull($omitida['archivo']);
        $this->assertSame(hash('sha3-256', $uno), $omitida['foto_hash']);
        $this->assertCount(2, $leeme['fotos']);
    }

    public function test_el_historial_se_acota_y_recorta_mensajes_largos(): void
    {
        config(['chat_claude.instantanea.historial_mensajes' => 3, 'chat_claude.instantanea.max_texto_mensaje' => 20]);
        foreach (['uno', 'dos'] as $t) {
            $this->preguntar($t);
            $this->mensajePorSecuencia((int) ChatMensaje::max('sequence'))->anadirTexto(str_repeat('x', 100));
            $this->mensajePorSecuencia((int) ChatMensaje::max('sequence'))->finalizar();
        }
        [$pregunta, $respuesta] = $this->preguntar('tres');

        app(InstantaneaDatos::class)->exportar($pregunta, $respuesta);

        $conv = json_decode(file_get_contents(InstantaneaDatos::ruta($respuesta->id).'/conversacion.json'), true);
        $this->assertCount(3, $conv['mensajes']);
        $this->assertSame('tres', $conv['mensajes'][2]['texto']);
        $this->assertStringEndsWith('… [recortado]', $conv['mensajes'][1]['texto']);
    }

    public function test_con_el_almacen_del_agente_se_sube_a_la_base_y_limpiar_lo_borra(): void
    {
        config(['agente.almacen' => 'base']);
        $this->datos();

        [, $dir, $respuesta] = $this->exportar();

        $rutas = AgenteArchivo::query()->pluck('ruta')->all();
        $this->assertContains("encargos/chat-{$respuesta->id}/LEEME.json", $rutas);
        $this->assertContains("encargos/chat-{$respuesta->id}/perfiles.json", $rutas);
        $this->assertSame(file_get_contents($dir.'/perfiles.json'), base64_decode((string) AgenteArchivo::query()->where('ruta', "encargos/chat-{$respuesta->id}/perfiles.json")->value('contenido')));

        InstantaneaDatos::limpiar($respuesta->id);

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame(0, AgenteArchivo::query()->count());
    }

    public function test_limpiar_borra_las_instantaneas_abandonadas_hace_mas_de_6_horas(): void
    {
        $vieja = $this->carpeta.'/encargos/chat-999';
        mkdir($vieja, 0775, true);
        file_put_contents($vieja.'/LEEME.json', '{}');
        touch($vieja, time() - 7 * 3600);
        $reciente = $this->carpeta.'/encargos/chat-998';
        mkdir($reciente, 0775, true);

        InstantaneaDatos::limpiar(1);

        $this->assertDirectoryDoesNotExist($vieja);
        $this->assertDirectoryExists($reciente);
    }

    public function test_con_el_presupuesto_agotado_los_archivos_restantes_se_omiten_y_nunca_se_pasa(): void
    {
        $this->datos();
        config(['chat_claude.instantanea.max_bytes_datos' => 40]);

        [$leeme, $dir] = $this->exportar();

        $suma = 0;
        foreach ($this->archivos($dir) as $f) {
            $suma += $f === 'LEEME.json' || $f === 'conversacion.json' || str_starts_with($f, 'fotos/') ? 0 : filesize($dir.'/'.$f);
        }
        $this->assertLessThanOrEqual(40, $suma);
        $this->assertNotEmpty(array_filter($leeme['archivos'], fn (array $a): bool => isset($a['omitido'])));
    }
}
