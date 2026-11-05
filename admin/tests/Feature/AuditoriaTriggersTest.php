<?php

namespace Tests\Feature;

use App\Enums\EstadoChat;
use App\Models\AgenteArchivo;
use App\Models\Alimento;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\Hogar;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La auditoria la hacen triggers de PostgreSQL: anotan cada alta, cambio y baja de las tablas de datos aunque no pasen por Eloquent.
 */
class AuditoriaTriggersTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $email = 'a@b.c'): User
    {
        return User::create(['name' => 'Admin', 'email' => $email, 'password' => 'password']);
    }

    /** @return Collection<int, Auditoria> */
    private function filas(string $modelo)
    {
        return Auditoria::where('auditable_type', $modelo)->orderBy('id')->get();
    }

    /** Escribe como lo haria psql: sin pasar por la aplicacion y con la sesion limpia de contexto. */
    private function comoPsql(string $sql): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (['user_id', 'user_nombre', 'user_email', 'ip', 'user_agent', 'url', 'metodo', 'instancia'] as $ajuste) {
            $pdo->exec("SELECT set_config('nutrition.{$ajuste}', '', false)");
        }
        $pdo->exec($sql);
    }

    public function test_un_alta_anota_modelo_id_usuario_instancia_y_valores(): void
    {
        $usuario = $this->usuario();
        $this->actingAs($usuario);

        $hogar = Hogar::create(['nombre' => 'Casa']);

        $fila = $this->filas(Hogar::class)->sole();
        $this->assertSame('creado', $fila->evento);
        $this->assertSame($hogar->id, $fila->auditable_id);
        $this->assertSame([$usuario->id, 'Admin', 'a@b.c'], [$fila->user_id, $fila->user_nombre, $fila->user_email]);
        $this->assertSame('prueba', $fila->instancia);
        $this->assertSame('hogares', $fila->datos['tabla']);
        $this->assertSame('Casa', $fila->valores_nuevos['nombre']);
        $this->assertNull($fila->valores_antiguos);
        $this->assertNotNull($fila->metodo);
        $this->assertNotNull($fila->created_at);
    }

    public function test_un_cambio_guarda_solo_lo_que_cambia_sin_updated_at(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);

        $hogar->update(['nombre' => 'Piso']);

        $fila = $this->filas(Hogar::class)->last();
        $this->assertSame('actualizado', $fila->evento);
        $this->assertSame(['nombre' => 'Casa'], $fila->valores_antiguos);
        $this->assertSame(['nombre' => 'Piso'], $fila->valores_nuevos);
    }

    public function test_tocar_updated_at_o_guardar_lo_mismo_no_es_un_evento(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);
        $antes = Auditoria::count();

        $hogar->touch();
        $hogar->update(['nombre' => 'Casa']);

        $this->assertSame($antes, Auditoria::count());
    }

    public function test_una_baja_guarda_la_fila_entera(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);

        $hogar->delete();

        $fila = $this->filas(Hogar::class)->last();
        $this->assertSame('eliminado', $fila->evento);
        $this->assertSame($hogar->id, $fila->auditable_id);
        $this->assertSame('Casa', $fila->valores_antiguos['nombre']);
        $this->assertNull($fila->valores_nuevos);
    }

    public function test_el_borrado_logico_es_eliminado_y_restaurar_es_restaurado(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);
        $menu = Menu::factory()->create(['hogar_id' => $hogar->id]);

        $menu->delete();
        $menu->restore();

        $eventos = $this->filas(Menu::class)->pluck('evento')->all();
        $this->assertSame(['creado', 'eliminado', 'restaurado'], $eventos);
        $this->assertSame($menu->id, $this->filas(Menu::class)->last()->auditable_id);
    }

    public function test_las_escrituras_que_no_pasan_por_eloquent_tambien_se_anotan(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);

        Hogar::query()->update(['nombre' => 'En bloque']);   // sin eventos de modelo
        DB::table('alimentos')->insert(['id' => Alimento::idDeClave('Pan'), 'nombre' => 'Pan', 'categoria' => 'cereal', 'unidad' => 'g', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hogares')->where('id', $hogar->id)->delete();

        $this->assertSame(['creado', 'actualizado', 'eliminado'], $this->filas(Hogar::class)->pluck('evento')->all());
        $alimento = $this->filas(Alimento::class)->sole();
        $this->assertSame([Alimento::idDeClave('Pan'), 'creado'], [$alimento->auditable_id, $alimento->evento]);
    }

    public function test_sin_la_aplicacion_queda_como_sql_con_el_rol_de_la_base(): void
    {
        $id = Alimento::idDeClave('Leche');

        $this->comoPsql("INSERT INTO alimentos (id, nombre, categoria, unidad, created_at, updated_at) VALUES ('{$id}', 'Leche', 'lacteo', 'l', now(), now())");

        $fila = $this->filas(Alimento::class)->sole();
        $this->assertSame('sql', $fila->metodo);
        $this->assertNull($fila->user_id);
        $this->assertNull($fila->instancia);
        $this->assertSame(config('database.connections.pgsql.username'), $fila->datos['rol']);
        $this->assertSame($id, $fila->auditable_id);
    }

    public function test_el_usuario_de_una_escritura_no_se_arrastra_a_la_siguiente(): void
    {
        $usuario = $this->usuario();
        $this->actingAs($usuario);
        Hogar::create(['nombre' => 'Con usuario']);

        Auth::logout();
        Hogar::create(['nombre' => 'Sin usuario']);

        $filas = $this->filas(Hogar::class);
        $this->assertSame([$usuario->id, null], $filas->pluck('user_id')->all());
        $this->assertSame(['Admin', null], $filas->pluck('user_nombre')->all());
    }

    public function test_la_contrasena_y_los_tokens_nunca_aparecen(): void
    {
        $this->actingAs($this->usuario());
        $otro = User::create(['name' => 'Otro', 'email' => 'o@b.c', 'password' => 'secreto-en-claro']);
        $otro->forceFill(['remember_token' => 'token-de-recordar'])->save();
        $otro->update(['password' => 'otra-clave-nueva']);

        $todo = json_encode($this->filas(User::class)->map->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('secreto-en-claro', $todo);
        $this->assertStringNotContainsString('token-de-recordar', $todo);
        $this->assertStringNotContainsString('otra-clave-nueva', $todo);
        $this->assertStringNotContainsString('$2y$', $todo, 'ni el hash');
        $creado = $this->filas(User::class)->first(fn (Auditoria $a): bool => $a->valores_nuevos['email'] === 'o@b.c');
        $this->assertSame('***', $creado->valores_nuevos['password']);
    }

    public function test_las_columnas_omitidas_solo_dejan_su_tamano(): void
    {
        $this->actingAs($this->usuario());

        AgenteArchivo::create(['ruta' => 'encargos/uno.json', 'contenido' => str_repeat('a', 1234)]);
        ChatMensaje::create(['message_id' => 'm1', 'sequence' => 1, 'contenido' => [['tipo' => 'texto', 'texto' => 'mi cena secreta']], 'estado' => EstadoChat::Pendiente,
            'created_ms' => 1, 'updated_ms' => 1]);

        $archivo = $this->filas(AgenteArchivo::class)->sole();
        $this->assertSame('[omitido: 1234 bytes]', $archivo->valores_nuevos['contenido']);
        $mensaje = $this->filas(ChatMensaje::class)->sole();
        $this->assertStringStartsWith('[omitido:', $mensaje->valores_nuevos['contenido']);
        $this->assertStringNotContainsString('cena secreta', json_encode($mensaje->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_los_textos_largos_se_recortan_y_lo_sensible_dentro_de_un_json_se_enmascara(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);
        Perfil::create(['hogar_id' => $hogar->id, 'nombre' => 'Marta', 'notas' => str_repeat('n', 900)]);
        $hogar->forceFill(['cambios' => ['api_token' => 'zzz-secreto', 'texto' => 'ok']])->save();
        $hogar->forceFill(['cambios' => ['relleno' => str_repeat('r', 900), 'password' => 'abc']])->save();

        $perfil = $this->filas(Perfil::class)->sole();
        $this->assertSame(str_repeat('n', 500).'… [truncado]', $perfil->valores_nuevos['notas']);

        $cambios = $this->filas(Hogar::class)->where('evento', 'actualizado')->values();
        $this->assertEquals(['api_token' => '***', 'texto' => 'ok'], $cambios[0]->valores_nuevos['cambios']);   // jsonb no conserva el orden de las claves
        $this->assertStringEndsWith('… [truncado]', $cambios[1]->valores_nuevos['cambios']);
        $this->assertStringNotContainsString('abc', json_encode($cambios[1]->getAttributes()));
    }

    public function test_los_deltas_de_un_mensaje_no_son_eventos_pero_si_su_estado(): void
    {
        $this->actingAs($this->usuario());
        $mensaje = ChatMensaje::create(['message_id' => 'm1', 'sequence' => 1, 'contenido' => [['tipo' => 'texto', 'texto' => 'a']], 'estado' => EstadoChat::EnCurso,
            'created_ms' => 1, 'updated_ms' => 1]);
        $antes = Auditoria::count();

        $mensaje->update(['contenido' => [['tipo' => 'texto', 'texto' => 'ab']], 'razonamiento' => 'pienso', 'updated_ms' => 2]);
        $this->assertSame($antes, Auditoria::count());

        $mensaje->update(['estado' => EstadoChat::Completado, 'contenido' => [['tipo' => 'texto', 'texto' => 'abc']], 'updated_ms' => 3]);
        $fila = $this->filas(ChatMensaje::class)->last();
        $this->assertSame('actualizado', $fila->evento);
        $this->assertSame(['IN_PROGRESS'], [$fila->valores_antiguos['estado']]);
        $this->assertSame('COMPLETED', $fila->valores_nuevos['estado']);
    }

    public function test_una_transaccion_revertida_no_deja_auditoria(): void
    {
        $this->actingAs($this->usuario());
        $antes = Auditoria::count();

        try {
            DB::transaction(function (): void {
                Hogar::create(['nombre' => 'Fantasma']);
                throw new \RuntimeException('deshacer');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($antes, Auditoria::count());
        $this->assertSame(0, Hogar::count());
    }

    public function test_los_registros_de_auditoria_son_inmutables_en_la_base(): void
    {
        $this->actingAs($this->usuario());
        Hogar::create(['nombre' => 'Casa']);

        foreach (["UPDATE auditorias SET evento = 'falso'", 'DELETE FROM auditorias'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} deberia fallar");
            } catch (QueryException $e) {
                $this->assertStringContainsString('inmutables', $e->getMessage());
            }
        }
        $this->assertGreaterThan(0, Auditoria::count());
    }

    public function test_las_filas_de_auditoria_se_ordenan_por_id_en_el_orden_de_creacion(): void
    {
        $this->actingAs($this->usuario());
        foreach (range(1, 15) as $i) {
            Hogar::create(['nombre' => "Casa {$i}"]);
        }

        $nombres = $this->filas(Hogar::class)->map(fn (Auditoria $a): string => $a->valores_nuevos['nombre'])->all();

        $this->assertSame(array_map(fn (int $i): string => "Casa {$i}", range(1, 15)), $nombres, 'uuidv7 de PostgreSQL: monotono dentro de una sesion');
    }

    public function test_el_resto_de_modelos_tambien_se_audita(): void
    {
        $this->actingAs($this->usuario());
        $hogar = Hogar::create(['nombre' => 'Casa']);
        Perfil::create(['hogar_id' => $hogar->id, 'nombre' => 'Marta']);

        $this->assertSame(1, $this->filas(Perfil::class)->count());
    }
}
